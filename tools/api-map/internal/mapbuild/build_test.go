package mapbuild

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func fixture(t *testing.T) source.Bundle {
	t.Helper()
	b := source.Bundle{Documents: map[string]model.Object{}}
	for _, f := range []struct{ id, family, file string }{{"whm-openapi", "whm-api-1", "whm"}, {"cpanel-openapi", "cpanel-uapi", "uapi"}} {
		raw, err := os.ReadFile("testdata/" + f.file + ".json")
		if err != nil {
			t.Fatal(err)
		}
		dec := json.NewDecoder(bytes.NewReader(raw))
		dec.UseNumber()
		var doc model.Object
		if err := dec.Decode(&doc); err != nil {
			t.Fatal(err)
		}
		sum := sha256.Sum256(raw)
		hash := hex.EncodeToString(sum[:])
		b.Records = append(b.Records, model.Object{"id": f.id, "kind": "openapi", "api_family": f.family, "file": f.file + ".json", "url": "https://api.docs.cpanel.net/" + f.file + ".json", "sha256": hash, "stored_sha256": hash, "bytes": len(raw), "stored_bytes": len(raw), "compression": "none", "content_type": "application/json", "retrieved_at": "2026-10-13T00:00:00Z", "openapi": "3.0.2", "version": "11.138.0.10", "paths": len(doc["paths"].(map[string]any)), "operations": 1})
		b.Documents[f.id] = doc
	}
	return b
}
func operation(t *testing.T, m model.Map, id string) model.Operation {
	t.Helper()
	for _, o := range m.Operations {
		if o.ID == id {
			return o
		}
	}
	t.Fatalf("missing %s", id)
	return model.Operation{}
}
func TestBuildCanonicalIdentity(t *testing.T) {
	b := fixture(t)
	m, err := Build(b, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	for _, id := range []string{"whm1:apply_dmarc", "uapi:EmailAuth/apply_dmarc", "whm1:EcosystemFeatures/is_ready"} {
		operation(t, m, id)
	}
	ns := operation(t, m, "whm1:EcosystemFeatures/is_ready")
	if ns.Function != "EcosystemFeatures/is_ready" || ns.Module != nil {
		t.Fatal(ns)
	}
	stage := operation(t, m, "uapi:WebApp/stage")
	if len(stage.Variants) != 2 {
		t.Fatal(stage)
	}
	for _, v := range stage.Variants {
		if !strings.HasSuffix(v.ID, "#"+v.Method) {
			t.Fatal(v)
		}
		if v.Method == "POST" {
			raw, err := source.Pointer(m.Documents[v.OperationLocator.SourceID], *v.OperationLocator.Pointer)
			if err != nil {
				t.Fatal(err)
			}
			op := raw.(map[string]any)
			if op["requestBody"] == nil || len(v.Parameters) == 0 {
				t.Fatal("POST body/query lost")
			}
		}
	}
	scores := operation(t, m, "uapi:SiteQuality/get_all_scores")
	if scores.Variants[0].Risk.Classification != "unknown" {
		t.Fatal(scores)
	}
	found := false
	for _, p := range scores.Variants[0].Parameters {
		s, ok := p.Definition["schema"].(map[string]any)
		if ok && s["nullable"] == true {
			_, present := s["default"]
			if p.Definition["required"] == true && present && s["default"] == nil {
				found = true
			}
		}
	}
	if !found {
		t.Fatal("required nullable null default lost")
	}
	raw, _ := source.Pointer(m.Documents["cpanel-openapi"], "/paths/~1CCS~1add_delegate/get")
	op := raw.(map[string]any)
	if _, ok := op["x-cpanel-rpm-version"].(json.Number); !ok {
		t.Fatalf("RPM type lost: %v", op)
	}
	if _, ok := op["x-cpanel-available-version"].(string); !ok {
		t.Fatalf("version type lost: %v", op)
	}
}
func TestRiskEvidence(t *testing.T) {
	for _, tc := range []struct {
		name    string
		value   any
		present bool
		want    string
	}{{"true", true, true, "read-only"}, {"false", false, true, "mutating"}, {"absent", nil, false, "unknown"}} {
		t.Run(tc.name, func(t *testing.T) {
			b := fixture(t)
			op := b.Documents["whm-openapi"]["paths"].(map[string]any)["/apply_dmarc"].(map[string]any)["get"].(map[string]any)
			delete(op, "x-readonly")
			if tc.present {
				op["x-readonly"] = tc.value
			}
			m, err := Build(b, nil, nil)
			if err != nil {
				t.Fatal(err)
			}
			r := operation(t, m, "whm1:apply_dmarc").Variants[0].Risk
			if r.Classification != tc.want {
				t.Fatal(r)
			}
			if tc.present && len(r.Evidence) == 0 {
				t.Fatal("missing risk evidence")
			}
		})
	}
	b := fixture(t)
	op := b.Documents["whm-openapi"]["paths"].(map[string]any)["/apply_dmarc"].(map[string]any)["get"].(map[string]any)
	op["x-readonly"] = true
	a := annotation(b, "risk", "destructive")
	m, err := Build(b, []model.Annotation{a}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if operation(t, m, a.TargetID).Variants[0].Risk.Classification != "unknown" {
		t.Fatal("conflict grants safety")
	}
	found := false
	for _, d := range m.Diagnostics {
		if d.Code == "RISK_CONFLICT" {
			found = true
		}
	}
	if !found {
		t.Fatal("missing conflict diagnostic")
	}
	op["x-readonly"] = false
	m, err = Build(b, []model.Annotation{a}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if operation(t, m, a.TargetID).Variants[0].Risk.Classification != "destructive" {
		t.Fatal("evidenced destructive supplement ignored")
	}
}
func annotation(b source.Bundle, kind, conclusion string) model.Annotation {
	p := "/paths/~1apply_dmarc/get"
	return model.Annotation{ID: "reviewed", Scope: "operation", TargetID: "whm1:apply_dmarc", Kind: kind, Conclusion: conclusion, Rationale: "Synthetic reviewed evidence", Evidence: []model.Locator{{SourceID: "whm-openapi", SourceSHA256: b.Records[0]["sha256"].(string), Pointer: &p}}}
}
func TestAnnotationsAndAliases(t *testing.T) {
	b := fixture(t)
	a := annotation(b, "risk", "mutating")
	good := model.Alias{AliasID: "whm1:old", TargetID: "whm1:apply_dmarc", Reason: "Synthetic reviewed rename", Evidence: a.Evidence}
	if _, err := Build(b, []model.Annotation{a}, []model.Alias{good}); err != nil {
		t.Fatal(err)
	}
	stale := a
	stale.Evidence = append([]model.Locator{}, a.Evidence...)
	stale.Evidence[0].SourceSHA256 = strings.Repeat("0", 64)
	for _, tc := range []struct {
		name    string
		a       []model.Annotation
		aliases []model.Alias
	}{{"stale", []model.Annotation{stale}, nil}, {"cycle", nil, []model.Alias{{AliasID: "whm1:a", TargetID: "whm1:b", Reason: "cycle", Evidence: a.Evidence}, {AliasID: "whm1:b", TargetID: "whm1:a", Reason: "cycle", Evidence: a.Evidence}}}, {"ambiguous", nil, []model.Alias{good, {AliasID: good.AliasID, TargetID: "whm1:EcosystemFeatures/is_ready", Reason: "duplicate", Evidence: a.Evidence}}}, {"unknown annotation", []model.Annotation{{ID: "bad", Scope: "operation", TargetID: "whm1:absent", Kind: a.Kind, Conclusion: a.Conclusion, Rationale: a.Rationale, Evidence: a.Evidence}}, nil}, {"unknown target", nil, []model.Alias{{AliasID: "whm1:old", TargetID: "whm1:absent", Reason: "bad", Evidence: a.Evidence}}}} {
		t.Run(tc.name, func(t *testing.T) {
			if _, err := Build(b, tc.a, tc.aliases); err == nil {
				t.Fatal("accepted invalid supplement")
			}
		})
	}
}
func TestBuildIsDeterministic(t *testing.T) {
	b := fixture(t)
	first, err := Build(b, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	x, _ := model.Encode(first)
	for id, d := range b.Documents {
		raw, _ := model.Encode(d)
		dec := json.NewDecoder(bytes.NewReader(raw))
		dec.UseNumber()
		var rebuilt model.Object
		if err := dec.Decode(&rebuilt); err != nil {
			t.Fatal(err)
		}
		b.Documents[id] = rebuilt
	}
	second, err := Build(b, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	y, _ := model.Encode(second)
	if !bytes.Equal(x, y) {
		t.Fatal("object insertion order changed output")
	}
	doc := b.Documents["whm-openapi"]
	doc["x-ordered"] = []any{"a", "b"}
	m, err := Build(b, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	x, _ = model.Encode(m)
	doc["x-ordered"] = []any{"b", "a"}
	m, err = Build(b, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	y, _ = model.Encode(m)
	if bytes.Equal(x, y) {
		t.Fatal("semantic array order erased")
	}
}
func TestPinnedBuild(t *testing.T) {
	b, err := source.Load("../../../../docs/api/sources/2026-10-13/manifest.json", source.Limits{})
	if err != nil {
		t.Fatal(err)
	}
	m, err := Build(b, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	n, collisions := 0, 0
	for _, o := range m.Operations {
		n += len(o.Variants)
	}
	for _, d := range m.Diagnostics {
		if d.Code == "UPSTREAM_ID_COLLISION" {
			collisions++
		}
	}
	if len(m.Operations) != 1323 || n != 1325 || collisions != 29 {
		t.Fatalf("operations=%d variants=%d collisions=%d", len(m.Operations), n, collisions)
	}
}

func TestReviewedRiskUnknownConflictsRegardlessOfOrder(t *testing.T) {
	b := fixture(t)
	op := b.Documents["whm-openapi"]["paths"].(map[string]any)["/apply_dmarc"].(map[string]any)["get"].(map[string]any)
	delete(op, "x-readonly")
	unknown := annotation(b, "risk", "unknown")
	unknown.ID = "unknown"
	safe := annotation(b, "risk", "read-only")
	safe.ID = "safe"
	for _, annotations := range [][]model.Annotation{{unknown, safe}, {safe, unknown}} {
		m, err := Build(b, annotations, nil)
		if err != nil {
			t.Fatal(err)
		}
		if operation(t, m, unknown.TargetID).Variants[0].Risk.Classification != "unknown" {
			t.Fatal("conflicting unknown/read-only evidence grants safety")
		}
	}
}
func TestRetainsMethodlessPathsAndBasicAuth(t *testing.T) {
	b := fixture(t)
	b.Documents["whm-openapi"]["paths"].(map[string]any)["/methodless"] = map[string]any{"summary": "Retained evidence"}
	m, err := Build(b, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := source.Pointer(m.Documents["whm-openapi"], "/paths/~1methodless"); err != nil {
		t.Fatal(err)
	}
	if len(m.Operations) != 6 {
		t.Fatalf("methodless path became invented operation: %d", len(m.Operations))
	}
	raw, err := source.Pointer(m.Documents["whm-openapi"], "/components/securitySchemes/BasicAuth")
	if err != nil || raw == nil {
		t.Fatal("BasicAuth lost", err)
	}
}
func TestReviewedAuditAndTokenAnnotations(t *testing.T) {
	b, err := source.Load("../../../../docs/api/sources/2026-10-13/manifest.json", source.Limits{})
	if err != nil {
		t.Fatal(err)
	}
	a, err := LoadAnnotations("../../../../docs/api/annotations.json")
	if err != nil {
		t.Fatal(err)
	}
	m, err := Build(b, a.Annotations, a.Aliases)
	if err != nil {
		t.Fatal(err)
	}
	if len(m.Annotations) != 2 || len(m.Aliases) != 0 {
		t.Fatal("unexpected reviewed supplements")
	}
	seen := map[string]bool{}
	for _, d := range m.Diagnostics {
		seen[d.Code] = true
	}
	for _, code := range []string{"TOKEN_SCHEME_DISCREPANCY", "TLS_EXAMPLE_DISCREPANCY", "TRANSPORT_DISCREPANCY", "BRIDGE_ROUTE_DISCREPANCY", "BODY_PLACEMENT_UNRESOLVED", "RESPONSE_SEMANTICS_UNRESOLVED", "NULL_WIRE_SEMANTICS_UNRESOLVED"} {
		if !seen[code] {
			t.Errorf("missing reviewed audit %s", code)
		}
	}
	ssl := operation(t, m, "uapi:SSL/install_ssl")
	if len(ssl.Variants) != 1 || ssl.Variants[0].Method != "GET" {
		t.Fatal("invented SSL POST variant")
	}
}

func TestPOSTWithoutBodyRetainsQueryAndDiagnostic(t *testing.T) {
	b := fixture(t)
	post := b.Documents["cpanel-openapi"]["paths"].(map[string]any)["/WebApp/stage"].(map[string]any)["post"].(map[string]any)
	delete(post, "requestBody")
	m, err := Build(b, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	found := false
	for _, d := range m.Diagnostics {
		if d.Code == "POST_WITHOUT_BODY" && d.CanonicalID == "uapi:WebApp/stage" {
			found = true
		}
	}
	if !found {
		t.Fatal("missing POST body absence diagnostic")
	}
	for _, v := range operation(t, m, "uapi:WebApp/stage").Variants {
		if v.Method == "POST" && len(v.Parameters) == 0 {
			t.Fatal("query contract lost")
		}
	}
	raw, err := source.Pointer(m.Documents["cpanel-openapi"], "/paths/~1WebApp~1stage/post")
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := raw.(map[string]any)["requestBody"]; ok {
		t.Fatal("invented POST body")
	}
}

func TestLoadAnnotationsRejectsCaseVariantKeys(t *testing.T) {
	b := fixture(t)
	a := annotation(b, "risk", "mutating")
	alias := model.Alias{AliasID: "whm1:old", TargetID: "whm1:missing", Reason: "Synthetic invalid mapping", Evidence: a.Evidence}
	aliasRaw, err := json.Marshal(alias)
	if err != nil {
		t.Fatal(err)
	}
	annotationRaw, err := json.Marshal(a)
	if err != nil {
		t.Fatal(err)
	}
	for _, raw := range []string{
		`{"schema_version":"1.0","annotations":[],"aliases":[],"ALIASES":[]}`,
		`{"schema_version":"1.0","annotations":[],"aliases":[` + string(aliasRaw) + `],"ALIASES":[]}`,
		`{"schema_version":"1.0","annotations":[` + string(annotationRaw) + `],"ANNOTATIONS":[],"aliases":[]}`,
		`{"schema_version":"1.0","annotations":[],"aliases":[` + strings.Replace(string(aliasRaw), `"target_id":`, `"TARGET_ID":`, 1) + `]}`,
		`{"schema_version":"1.0","annotations":[` + strings.Replace(string(annotationRaw), `"conclusion":`, `"CONCLUSION":`, 1) + `],"aliases":[]}`,
	} {
		t.Run(raw, func(t *testing.T) {
			path := t.TempDir() + "/annotations.json"
			if err := os.WriteFile(path, []byte(raw), 0600); err != nil {
				t.Fatal(err)
			}
			if _, err := LoadAnnotations(path); err == nil {
				t.Fatal("accepted schema-forbidden case variant")
			}
		})
	}
}
func TestAuditReviewRequiresAllSourceDependencies(t *testing.T) {
	for _, tc := range []struct {
		name, dependency, code, id string
		changeMethod               bool
	}{
		{"SSL spec changed", "cpanel-openapi", "TRANSPORT_DISCREPANCY", "uapi:SSL/install_ssl", true},
		{"SSL tutorial changed", "guides--quickstart-development-guide--tutorial-call-uapis-ssl-install-ssl-function-in-custom-code", "TRANSPORT_DISCREPANCY", "uapi:SSL/install_ssl", false},
		{"bridge route source changed", "whm--introduction", "BRIDGE_ROUTE_DISCREPANCY", "", false},
		{"bridge guide changed", "whm--use-whm-api-to-call-cpanel-api-and-uapi", "BRIDGE_ROUTE_DISCREPANCY", "", false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			b, err := source.Load("../../../../docs/api/sources/2026-10-13/manifest.json", source.Limits{})
			if err != nil {
				t.Fatal(err)
			}
			var changed model.Object
			for _, r := range b.Records {
				if r["id"] == tc.dependency {
					changed = r
					break
				}
			}
			if changed == nil {
				t.Fatal("missing test dependency")
			}
			if tc.changeMethod {
				doc := b.Documents[tc.dependency]
				path := doc["paths"].(map[string]any)["/SSL/install_ssl"].(map[string]any)
				path["post"] = path["get"]
				delete(path, "get")
				raw, err := model.Encode(doc)
				if err != nil {
					t.Fatal(err)
				}
				sum := sha256.Sum256(raw)
				changed["sha256"] = hex.EncodeToString(sum[:])
				changed["stored_sha256"] = changed["sha256"]
				changed["compression"] = "none"
				changed["bytes"] = len(raw)
				changed["stored_bytes"] = len(raw)
			} else {
				sum := sha256.Sum256([]byte("changed offline guide evidence"))
				changed["sha256"] = hex.EncodeToString(sum[:])
			}
			m, err := Build(b, nil, nil)
			if err != nil {
				t.Fatal(err)
			}
			reviewNeeded := false
			for _, d := range m.Diagnostics {
				if d.Code == tc.code {
					t.Fatalf("stale reviewed fact reused: %s", d.Message)
				}
				if d.Code == "AUDIT_REVIEW_NEEDED" && strings.Contains(d.Message, tc.code) {
					reviewNeeded = true
					if d.Locator.SourceID != tc.dependency || d.Locator.SourceSHA256 != changed["sha256"] {
						t.Fatal("review warning not bound to current dependency", d)
					}
				}
			}
			if !reviewNeeded {
				t.Fatal("missing explicit review-needed diagnostic")
			}
			if tc.changeMethod {
				o := operation(t, m, tc.id)
				if len(o.Variants) != 1 || o.Variants[0].Method != "POST" {
					t.Fatal("changed source contract not retained")
				}
			}
		})
	}
}
