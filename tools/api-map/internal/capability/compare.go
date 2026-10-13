package capability

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

// Explanation is a reviewed account of one exact set difference. RelatedName
// records an explicit namespace/alias relationship without changing either set.
// Empty evidence is reserved for automatically generated unknown explanations.
type Explanation struct {
	Side        string          `json:"side"`
	Name        string          `json:"name"`
	Category    string          `json:"category"`
	Certainty   string          `json:"certainty"`
	Rationale   string          `json:"rationale"`
	Evidence    []model.Locator `json:"evidence"`
	RelatedName string          `json:"related_name,omitempty"`
}

// InventoryComparison uses null runtime counts and sets when no inventory was
// observed; unavailable must never be reported as an empty successful inventory.
type InventoryComparison struct {
	Family          string   `json:"family"`
	InventoryStatus string   `json:"inventory_status"`
	DocumentedCount int      `json:"documented_count"`
	AdvertisedCount *int     `json:"advertised_count"`
	Shared          []string `json:"shared"`
	DocsOnly        []string `json:"docs_only"`
	RuntimeOnly     []string `json:"runtime_only"`
}

type Comparison struct {
	SchemaVersion string              `json:"schema_version"`
	Profile       Profile             `json:"profile"`
	WHM           InventoryComparison `json:"whm"`
	UAPI          InventoryComparison `json:"uapi"`
	Explanations  []Explanation       `json:"explanations"`
}

// LoadExplanations accepts a versioned wrapper, preserving exact JSON keys.
func LoadExplanations(raw []byte) ([]Explanation, error) {
	var value any
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(&value); err != nil {
		return nil, fmt.Errorf("invalid explanations")
	}
	if decoder.Decode(new(any)) != io.EOF {
		return nil, fmt.Errorf("invalid explanations")
	}
	if err := schema.Validate("capability-explanations-v1.schema.json", value); err != nil {
		return nil, fmt.Errorf("invalid explanations: %w", err)
	}
	var file struct {
		SchemaVersion string        `json:"schema_version"`
		Explanations  []Explanation `json:"explanations"`
	}
	if err := json.Unmarshal(raw, &file); err != nil {
		return nil, err
	}
	return file.Explanations, nil
}

// Compare is an offline exact-name comparison. It does not infer version
// ordering, aliases, support, PHP implementation, or a UAPI runtime inventory.
func Compare(m model.Map, p Profile, explanations []Explanation) (Comparison, error) {
	if m.SchemaVersion != "1.0" {
		return Comparison{}, fmt.Errorf("invalid map schema version")
	}
	// Validate and detach the saved profile, including its capture provenance.
	raw, err := model.Encode(p)
	if err != nil {
		return Comparison{}, err
	}
	p, err = DecodeProfile(raw)
	if err != nil {
		return Comparison{}, err
	}
	report := Comparison{SchemaVersion: "1.0", Profile: p, WHM: InventoryComparison{Family: "whm1", InventoryStatus: "unavailable"}, UAPI: InventoryComparison{Family: "uapi", InventoryStatus: "unavailable"}, Explanations: []Explanation{}}
	documented := map[string]bool{}
	uapi := map[string]bool{}
	for _, op := range m.Operations {
		name := strings.TrimPrefix(op.Path, "/")
		if name == "" || op.ID != op.Family+":"+name {
			return Comparison{}, fmt.Errorf("invalid canonical operation identity")
		}
		switch op.Family {
		case "whm1":
			documented[name] = true
		case "uapi":
			uapi[name] = true
		default:
			return Comparison{}, fmt.Errorf("invalid API family")
		}
	}
	report.WHM.DocumentedCount = len(documented)
	report.UAPI.DocumentedCount = len(uapi)
	if p.CaptureStatus == "success" {
		report.WHM.InventoryStatus = "available"
		report.WHM.Shared = []string{}
		report.WHM.DocsOnly = []string{}
		report.WHM.RuntimeOnly = []string{}
		advertised := map[string]bool{}
		for _, name := range p.AdvertisedNames {
			advertised[name] = true
		}
		count := len(advertised)
		report.WHM.AdvertisedCount = &count
		for name := range documented {
			if advertised[name] {
				report.WHM.Shared = append(report.WHM.Shared, name)
			} else {
				report.WHM.DocsOnly = append(report.WHM.DocsOnly, name)
			}
		}
		for name := range advertised {
			if !documented[name] {
				report.WHM.RuntimeOnly = append(report.WHM.RuntimeOnly, name)
			}
		}
		sort.Strings(report.WHM.Shared)
		sort.Strings(report.WHM.DocsOnly)
		sort.Strings(report.WHM.RuntimeOnly)
	}
	differences := map[string]bool{}
	for _, name := range report.WHM.DocsOnly {
		differences["docs-only\x00"+name] = true
	}
	for _, name := range report.WHM.RuntimeOnly {
		differences["runtime-only\x00"+name] = true
	}
	reviewed := map[string]bool{}
	for _, explanation := range explanations {
		// Schema validation checks category/certainty and exact evidence locators.
		if err := schema.Validate("capability-explanations-v1.schema.json", map[string]any{"schema_version": "1.0", "explanations": []Explanation{explanation}}); err != nil {
			return Comparison{}, fmt.Errorf("invalid explanation: %w", err)
		}
		key := explanation.Side + "\x00" + explanation.Name
		if !differences[key] {
			return Comparison{}, fmt.Errorf("explanation does not name an exact difference")
		}
		if explanation.RelatedName != "" {
			opposite := "docs-only"
			if explanation.Side == "docs-only" {
				opposite = "runtime-only"
			}
			if explanation.Category != "namespace/alias" || !differences[opposite+"\x00"+explanation.RelatedName] {
				return Comparison{}, fmt.Errorf("relationship must name the opposite exact difference")
			}
		}
		// Detach and sort evidence; source arrays in the saved profile remain intact.
		raw, _ := json.Marshal(explanation)
		var detached Explanation
		_ = json.Unmarshal(raw, &detached)
		sort.Slice(detached.Evidence, func(i, j int) bool {
			a, _ := json.Marshal(detached.Evidence[i])
			b, _ := json.Marshal(detached.Evidence[j])
			return string(a) < string(b)
		})
		report.Explanations = append(report.Explanations, detached)
		reviewed[key] = true
	}
	for key := range differences {
		if !reviewed[key] {
			parts := strings.SplitN(key, "\x00", 2)
			report.Explanations = append(report.Explanations, Explanation{Side: parts[0], Name: parts[1], Category: "unknown", Certainty: "unknown", Rationale: "No reviewed evidence establishes the cause of this difference.", Evidence: []model.Locator{}})
		}
	}
	sort.Slice(report.Explanations, func(i, j int) bool {
		a, _ := json.Marshal(report.Explanations[i])
		b, _ := json.Marshal(report.Explanations[j])
		return string(a) < string(b)
	})
	if err := schema.Validate("capability-report-v1.schema.json", report); err != nil {
		return Comparison{}, fmt.Errorf("invalid capability report: %w", err)
	}
	return report, nil
}
