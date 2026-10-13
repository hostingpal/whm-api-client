package model_test

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/testutil"
)

func TestMapRoundTripPreservesSourceNumbers(t *testing.T) {
	input := testutil.Read(t, "testdata/map-valid.json")
	m, err := model.DecodeMap(input)
	if err != nil {
		t.Fatal(err)
	}
	if value := m.Documents["synthetic-uapi"]["x-large-integer"]; value != json.Number("9007199254740993") {
		t.Fatalf("rounded: %v", value)
	}
	encoded, err := model.Encode(m)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Contains(encoded, []byte("9007199254740993")) {
		t.Fatal("lost number")
	}
	again, err := model.DecodeMap(encoded)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(m.Documents, again.Documents) || !reflect.DeepEqual(m.Sources, again.Sources) {
		t.Fatal("round trip lost source data")
	}
	p := m.Operations[0].Variants[0].Parameters
	schema := p[0].Definition["schema"].(map[string]any)
	if value, exists := schema["default"]; !exists || value != nil {
		t.Fatal("required null default lost")
	}
	absent := p[1].Definition["schema"].(map[string]any)
	if _, exists := absent["default"]; exists {
		t.Fatal("invented absent default")
	}
	if absent["nullable"] != false {
		t.Fatal("lost explicit false")
	}
	if !m.Operations[0].Variants[0].Security.Present {
		t.Fatal("lost explicit empty security")
	}
	if m.Operations[0].Variants[1].Security.Present {
		t.Fatal("invented absent security")
	}
}

func TestDecodeMapRejectsInvalidJSON(t *testing.T) {
	valid := testutil.Read(t, "testdata/map-valid.json")
	for _, input := range [][]byte{append(append([]byte{}, valid...), []byte("{}")...), []byte(`{"schema_version":"1.0","schema_version":"1.0"}`), []byte(`{"schema_version":"1.0"}`)} {
		if _, err := model.DecodeMap(input); err == nil {
			t.Fatalf("accepted invalid map: %s", input)
		}
	}
}

func TestEncodeDeterministicAndNonMutating(t *testing.T) {
	m, err := model.DecodeMap(testutil.Read(t, "testdata/map-valid.json"))
	if err != nil {
		t.Fatal(err)
	}
	originalFirst := m.Operations[0].ID
	m.Operations[0], m.Operations[1] = m.Operations[1], m.Operations[0]
	originalReordered := m.Operations[0].ID
	encoded, err := model.Encode(m)
	if err != nil {
		t.Fatal(err)
	}
	if m.Operations[0].ID != originalReordered {
		t.Fatal("encode mutated operations")
	}
	restored, err := model.DecodeMap(encoded)
	if err != nil {
		t.Fatal(err)
	}
	if restored.Operations[0].ID != originalFirst {
		t.Fatal("operations not sorted")
	}
	if restored.Sources[0]["id"] != "synthetic-whm" {
		t.Fatal("source order changed")
	}
	second, err := model.Encode(restored)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(encoded, second) {
		t.Fatal("encoding not deterministic")
	}
	if encoded[len(encoded)-1] != '\n' || !bytes.Contains(encoded, []byte("\n  \"schema_version\"")) {
		t.Fatal("wrong JSON formatting")
	}
	if _, err := model.Encode(make(chan int)); err == nil {
		t.Fatal("ignored encoding error")
	}
}

func TestAtomicWriteAcceptsValidatedArtifactBytes(t *testing.T) {
	artifacts := map[string][]byte{
		"map.json":   []byte("{\"validated\":true}\n"),
		"report.md":  []byte("# Coverage\n\nSynthetic report: 0 implemented.\n"),
		"map.sha256": []byte("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  map.json\n"),
	}
	for name, data := range artifacts {
		t.Run(name, func(t *testing.T) {
			directory := filepath.Join(t.TempDir(), "new")
			path := filepath.Join(directory, name)
			if err := model.WriteAtomic(path, data); err != nil {
				t.Fatal(err)
			}
			if !bytes.Equal(testutil.Read(t, path), data) {
				t.Fatal("artifact bytes changed")
			}
			entries, err := os.ReadDir(directory)
			if err != nil {
				t.Fatal(err)
			}
			if len(entries) != 1 {
				t.Fatal("temporary files leaked")
			}
		})
	}
}

func TestInvalidJSONMapPreservesPreviousOutput(t *testing.T) {
	path := filepath.Join(t.TempDir(), "map.json")
	previous := testutil.Read(t, "testdata/map-valid.json")
	if err := model.WriteAtomic(path, previous); err != nil {
		t.Fatal(err)
	}
	for _, input := range [][]byte{[]byte("invalid JSON"), []byte(`{"schema_version":"1.0"}`)} {
		value, err := model.DecodeMap(input)
		if err == nil {
			data, encodeErr := model.Encode(value)
			if encodeErr != nil {
				t.Fatal(encodeErr)
			}
			if writeErr := model.WriteAtomic(path, data); writeErr != nil {
				t.Fatal(writeErr)
			}
			t.Fatal("invalid JSON map passed caller validation")
		}
		if !bytes.Equal(testutil.Read(t, path), previous) {
			t.Fatal("invalid map replaced previous output")
		}
	}
}

func TestAtomicWritePreservesPrevious(t *testing.T) {
	root := t.TempDir()
	blocked := filepath.Join(root, "report.md")
	if err := os.Mkdir(blocked, 0755); err != nil {
		t.Fatal(err)
	}
	previousPath := filepath.Join(blocked, "previous.md")
	previous := []byte("# Previous report\n")
	if err := os.WriteFile(previousPath, previous, 0644); err != nil {
		t.Fatal(err)
	}
	if err := model.WriteAtomic(blocked, []byte("# Replacement report\n")); err == nil {
		t.Fatal("replaced directory")
	}
	if !bytes.Equal(testutil.Read(t, previousPath), previous) {
		t.Fatal("failed rename altered previous contents")
	}
	entries, err := os.ReadDir(root)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 1 || entries[0].Name() != "report.md" {
		t.Fatal("failed rename leaked temporary file")
	}
	blockedParent := filepath.Join(t.TempDir(), "parent")
	if err := os.WriteFile(blockedParent, previous, 0644); err != nil {
		t.Fatal(err)
	}
	if err := model.WriteAtomic(filepath.Join(blockedParent, "report.md"), previous); err == nil {
		t.Fatal("wrote through non-directory parent")
	}
	if !bytes.Equal(testutil.Read(t, blockedParent), previous) {
		t.Fatal("failed creation altered existing parent")
	}
}

func TestDecodeMapRejectsDuplicateRawKeysAndInvalidUTF8(t *testing.T) {
	valid := testutil.Read(t, "testdata/map-valid.json")
	duplicate := bytes.Replace(valid, []byte(`"x-readonly": false`), []byte(`"x-readonly": false, "x-readonly": true`), 1)
	if _, err := model.DecodeMap(duplicate); err == nil {
		t.Fatal("silently discarded duplicate raw document key")
	}
	malformed := bytes.Replace(valid, []byte(`"openapi": "3.0.3"`), append([]byte(`"openapi": "`), 0xff, '"'), 1)
	if _, err := model.DecodeMap(malformed); err == nil {
		t.Fatal("silently replaced invalid UTF-8")
	}
}

func TestEncodeSortsAllNormalizedRecords(t *testing.T) {
	m, err := model.DecodeMap(testutil.Read(t, "testdata/map-valid.json"))
	if err != nil {
		t.Fatal(err)
	}
	operation := &m.Operations[0]
	operation.Variants[0], operation.Variants[1] = operation.Variants[1], operation.Variants[0]
	loc := operation.Provenance[0]
	later := loc
	pointer := "/z"
	later.Pointer = &pointer
	operation.Provenance = []model.Locator{later, loc}
	operation.Variants[1].Risk.Evidence = []model.Locator{later, loc}
	m.Aliases = []model.Alias{{AliasID: "uapi:Demo/z", TargetID: operation.ID, Reason: "Synthetic", Evidence: []model.Locator{later, loc}}, {AliasID: "uapi:Demo/a", TargetID: operation.ID, Reason: "Synthetic", Evidence: []model.Locator{loc}}}
	m.Annotations = []model.Annotation{{ID: "z", Scope: "family", TargetID: "uapi", Kind: "synthetic", Conclusion: "synthetic", Rationale: "Synthetic", Evidence: []model.Locator{later, loc}}, {ID: "a", Scope: "family", TargetID: "uapi", Kind: "synthetic", Conclusion: "synthetic", Rationale: "Synthetic", Evidence: []model.Locator{loc}}}
	m.Diagnostics = []model.Diagnostic{{Severity: "info", Code: "synthetic", Message: "Synthetic", Locator: later}, {Severity: "info", Code: "synthetic", Message: "Synthetic", Locator: loc}}
	encoded, err := model.Encode(m)
	if err != nil {
		t.Fatal(err)
	}
	sorted, err := model.DecodeMap(encoded)
	if err != nil {
		t.Fatal(err)
	}
	op := sorted.Operations[0]
	if op.Variants[0].Method != "GET" {
		t.Fatal("variants not sorted")
	}
	if op.Variants[0].Parameters[0].Name != "absent_default" {
		t.Fatal("parameters not sorted")
	}
	if *op.Provenance[0].Pointer != "/paths/~1Demo~1inspect/get" {
		t.Fatal("provenance not sorted")
	}
	if *op.Variants[0].Risk.Evidence[0].Pointer != "/paths/~1Demo~1inspect/get" {
		t.Fatal("risk evidence not sorted")
	}
	if sorted.Aliases[0].AliasID != "uapi:Demo/a" || sorted.Annotations[0].ID != "a" {
		t.Fatal("aliases or annotations not sorted")
	}
	if *sorted.Diagnostics[0].Locator.Pointer != "/paths/~1Demo~1inspect/get" {
		t.Fatal("diagnostics not sorted")
	}
	if *sorted.Annotations[1].Evidence[0].Pointer != "/paths/~1Demo~1inspect/get" {
		t.Fatal("annotation evidence not sorted")
	}
	if m.Operations[0].Variants[0].Method != "POST" {
		t.Fatal("sorting mutated caller")
	}
}

func TestPublishedExampleIsSyntheticAndValid(t *testing.T) {
	m, err := model.DecodeMap(testutil.Read(t, "../../../../docs/api/examples/api-map-v1.json"))
	if err != nil {
		t.Fatal(err)
	}
	if len(m.Operations) != 2 || len(m.Operations[0].Variants) != 2 {
		t.Fatal("example must demonstrate one WHM operation and a two-method UAPI operation")
	}
	for _, source := range m.Sources {
		if source["synthetic"] != true {
			t.Fatal("example source is not labeled synthetic")
		}
	}
	if m.Operations[1].ID != "whm1:Namespace/inspect" {
		t.Fatal("WHM namespace missing")
	}
	if m.Operations[0].Variants[0].Method != "GET" || m.Operations[0].Variants[1].Method != "POST" {
		t.Fatal("UAPI example lacks GET/POST variants")
	}
}

func TestSyntheticSecurityMatchesRetainedSource(t *testing.T) {
	for _, path := range []string{"testdata/map-valid.json", "../../../../docs/api/examples/api-map-v1.json"} {
		t.Run(path, func(t *testing.T) {
			m, err := model.DecodeMap(testutil.Read(t, path))
			if err != nil {
				t.Fatal(err)
			}
			paths := m.Documents["synthetic-uapi"]["paths"].(map[string]any)
			item := paths["/Demo/inspect"].(map[string]any)
			get := item["get"].(map[string]any)
			security, exists := get["security"]
			if !exists {
				t.Fatal("present effective security points to absent retained source field")
			}
			empty, ok := security.([]any)
			if !ok || len(empty) != 0 {
				t.Fatal("GET source security is not an explicit empty array")
			}
			if !reflect.DeepEqual(m.Operations[0].Variants[0].Security.Value, security) {
				t.Fatal("effective GET security differs from source declaration")
			}
			if _, exists := item["post"].(map[string]any)["security"]; exists {
				t.Fatal("POST source security must remain absent")
			}
			if m.Operations[0].Variants[1].Security.Present {
				t.Fatal("POST effective security must remain absent")
			}
		})
	}
}
