package source

import (
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"testing"
)

func hash(data []byte) string { s := sha256.Sum256(data); return hex.EncodeToString(s[:]) }
func fixture(t *testing.T, raw string, file string) (string, map[string]any) {
	t.Helper()
	dir := t.TempDir()
	var compressed bytes.Buffer
	g := gzip.NewWriter(&compressed)
	_, _ = g.Write([]byte(raw))
	_ = g.Close()
	if err := os.WriteFile(filepath.Join(dir, file), compressed.Bytes(), 0600); err != nil {
		t.Fatal(err)
	}
	rec := map[string]any{"id": "spec", "kind": "openapi", "api_family": "whm-api-1", "url": "https://api.docs.cpanel.net/spec.json", "retrieved_at": "2026-10-13T00:00:00Z", "file": file, "compression": "gzip", "bytes": len(raw), "stored_bytes": compressed.Len(), "sha256": hash([]byte(raw)), "stored_sha256": hash(compressed.Bytes()), "content_type": "application/json"}
	path := filepath.Join(dir, "manifest.json")
	writeManifest(t, path, []map[string]any{rec})
	return path, rec
}
func writeManifest(t *testing.T, path string, records []map[string]any) {
	t.Helper()
	data, err := json.Marshal(map[string]any{"schema_version": 1, "snapshot_id": "fixture", "hash_algorithm": "sha256", "sources": records})
	if err != nil {
		t.Fatal(err)
	}
	if err = os.WriteFile(path, data, 0600); err != nil {
		t.Fatal(err)
	}
}

//go:embed testdata/valid-openapi.json
var valid string

func TestLoadPinnedSources(t *testing.T) {
	b, err := Load("../../../../docs/api/sources/2026-10-13/manifest.json", DefaultLimits())
	if err != nil {
		t.Fatal(err)
	}
	if len(b.Records) != 23 || len(b.Documents) != 2 {
		t.Fatalf("unexpected bundle: %d/%d", len(b.Records), len(b.Documents))
	}
}
func TestLoadPreservesValues(t *testing.T) {
	p, _ := fixture(t, valid, "spec.json.gz")
	b, err := Load(p, DefaultLimits())
	if err != nil {
		t.Fatal(err)
	}
	d := b.Documents["spec"]
	if d["x-number"] != json.Number("9007199254740993") || d["x-null"] != nil || d["x-false"] != false || d["x-empty"] != "" {
		t.Fatalf("lossy values: %#v", d)
	}
	if _, ok := d["missing"]; ok {
		t.Fatal("invented absent field")
	}
}
func TestLoadRejectsUnsafeInputs(t *testing.T) {
	for _, tc := range []struct {
		name, raw, file string
		mutate          func(*testing.T, string, map[string]any)
	}{
		{name: "compressed changed", raw: valid, file: "spec.gz", mutate: func(t *testing.T, p string, r map[string]any) {
			data, _ := os.ReadFile(filepath.Join(filepath.Dir(p), "spec.gz"))
			data[len(data)/2] ^= 1
			_ = os.WriteFile(filepath.Join(filepath.Dir(p), "spec.gz"), data, 0600)
		}},
		{name: "wrong raw hash", raw: valid, file: "spec.gz", mutate: func(t *testing.T, p string, r map[string]any) {
			r["sha256"] = hash([]byte("wrong"))
			writeManifest(t, p, []map[string]any{r})
		}},
		{name: "wrong size", raw: valid, file: "spec.gz", mutate: func(t *testing.T, p string, r map[string]any) {
			r["bytes"] = 1
			writeManifest(t, p, []map[string]any{r})
		}},
		{name: "duplicate JSON", raw: `{"openapi":"3.0.2","openapi":"3.0.2"}`, file: "spec.gz"},
		{name: "trailing JSON", raw: valid + ` {}`, file: "spec.gz"},
		{name: "duplicate YAML", raw: "openapi: 3.0.2\nopenapi: 3.0.2\n", file: "spec.yaml.gz"},
		{name: "nonstring YAML", raw: "openapi: 3.0.2\n1: value\n", file: "spec.yaml.gz"},
		{name: "tag YAML", raw: "openapi: 3.0.2\nx: !custom value\n", file: "spec.yaml.gz"},
		{name: "cyclic YAML", raw: "openapi: 3.0.2\nx: &x [*x]\n", file: "spec.yaml.gz"},
		{name: "multi YAML", raw: "openapi: 3.0.2\n---\nopenapi: 3.0.2\n", file: "spec.yaml.gz"},
		{name: "escape", raw: valid, file: "spec.gz", mutate: func(t *testing.T, p string, r map[string]any) {
			r["file"] = "../outside"
			writeManifest(t, p, []map[string]any{r})
		}},
		{name: "symlink escape", raw: valid, file: "spec.gz", mutate: func(t *testing.T, p string, r map[string]any) {
			outside := filepath.Join(t.TempDir(), "outside")
			data, _ := os.ReadFile(filepath.Join(filepath.Dir(p), "spec.gz"))
			_ = os.WriteFile(outside, data, 0600)
			_ = os.Remove(filepath.Join(filepath.Dir(p), "spec.gz"))
			if err := os.Symlink(outside, filepath.Join(filepath.Dir(p), "spec.gz")); err != nil {
				t.Fatal(err)
			}
		}},
		{name: "missing external", raw: `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"X":{"$ref":"https://docs.cpanel.net/missing.json#/X"}}}}`, file: "spec.gz"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			p, r := fixture(t, tc.raw, tc.file)
			if tc.mutate != nil {
				tc.mutate(t, p, r)
			}
			b, err := Load(p, DefaultLimits())
			if err == nil || b.Records != nil || b.Documents != nil {
				t.Fatalf("expected error and no bundle: %#v %v", b, err)
			}
		})
	}
}
func TestLoadBounds(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	for _, l := range []Limits{{MaxInputBytes: 10}, {MaxDecompressedBytes: 10}, {MaxSourceSetBytes: 10}, {MaxDepth: 2}} {
		if _, err := Load(p, l); err == nil {
			t.Fatal("limit ignored")
		}
	}
}
func TestLoadReferencesOffline(t *testing.T) {
	p, _ := fixture(t, `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"X":{"$ref":"#/components/schemas/Y"},"Y":{"type":"string"}}}}`, "spec.gz")
	b, err := Load(p, DefaultLimits())
	if err != nil {
		t.Fatal(err)
	}
	id, ptr, err := ResolveReference(b, "spec", "#/components/schemas/Y")
	if err != nil || id != "spec" || ptr != "/components/schemas/Y" {
		t.Fatalf("resolve: %s %s %v", id, ptr, err)
	}
}

func TestLoadPinnedFileAndURLReferences(t *testing.T) {
	raw := `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"Local":{"$ref":"common.json#/Thing"},"Remote":{"$ref":"https://docs.cpanel.net/original.json#/Thing"},"Resolved":{"$ref":"https://docs.cpanel.net/resolved.json#/Thing"}}}}`
	p, r := fixture(t, raw, "spec.gz")
	q, reference := fixture(t, `{"Thing":{"type":"integer","default":9007199254740993}}`, "common.json.gz")
	reference["id"] = "common"
	reference["kind"] = "reference"
	reference["url"] = "https://docs.cpanel.net/original.json"
	reference["resolved_url"] = "https://docs.cpanel.net/resolved.json"
	data, _ := os.ReadFile(filepath.Join(filepath.Dir(q), "common.json.gz"))
	if err := os.WriteFile(filepath.Join(filepath.Dir(p), "common.json.gz"), data, 0600); err != nil {
		t.Fatal(err)
	}
	writeManifest(t, p, []map[string]any{r, reference})
	calls := 0
	previous := http.DefaultTransport
	http.DefaultTransport = roundTrip(func(req *http.Request) (*http.Response, error) { calls++; return nil, fmt.Errorf("forbidden network") })
	defer func() { http.DefaultTransport = previous }()
	b, err := Load(p, DefaultLimits())
	if err != nil {
		t.Fatal(err)
	}
	for _, ref := range []string{"common.json#/Thing", "https://docs.cpanel.net/original.json#/Thing", "https://docs.cpanel.net/resolved.json#/Thing"} {
		id, ptr, err := ResolveReference(b, "spec", ref)
		if err != nil || id != "common" || ptr != "/Thing" {
			t.Fatalf("resolve %s: %s %s %v", ref, id, ptr, err)
		}
	}
	if calls != 0 {
		t.Fatal("ordinary loading requested HTTP")
	}
}
func TestLoadYAMLPreservesValuesAndBoundsExpansion(t *testing.T) {
	raw := "openapi: 3.0.2\ninfo: {title: Fixture, version: '1'}\npaths: {}\nx-large: 9007199254740993\nx-null: null\nx-false: false\nx-empty: ''\nx-original: &shared {first: one, second: two}\nx-copy: *shared\n"
	p, _ := fixture(t, raw, "spec.yaml.gz")
	b, err := Load(p, DefaultLimits())
	if err != nil {
		t.Fatal(err)
	}
	d := b.Documents["spec"]
	if d["x-large"] != json.Number("9007199254740993") || d["x-null"] != nil || d["x-false"] != false || d["x-empty"] != "" {
		t.Fatalf("lossy YAML: %#v", d)
	}
	if _, err = Load(p, Limits{MaxAliasNodes: 4}); err == nil {
		t.Fatal("expanded alias nodes including mapping keys exceeded budget")
	}
}
func TestLoadRejectsInvalidReference(t *testing.T) {
	for _, ref := range []string{"#/missing", "../outside.json#/X", "#/bad~escape", "#/components/schemas/X/00", "#/anchor"} {
		t.Run(ref, func(t *testing.T) {
			raw := `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"X":["first"],"Y":{"$ref":` + strconv.Quote(ref) + `}}}}`
			p, _ := fixture(t, raw, "spec.gz")
			if _, err := Load(p, DefaultLimits()); err == nil {
				t.Fatal("invalid reference accepted")
			}
		})
	}
}

func TestLoadRejectsMalformedReferenceAndManifestIdentities(t *testing.T) {
	t.Run("nonstring ref", func(t *testing.T) {
		p, _ := fixture(t, `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"X":{"$ref":null}}}}`, "spec.gz")
		if _, err := Load(p, DefaultLimits()); err == nil {
			t.Fatal("nonstring reference accepted")
		}
	})
	t.Run("aliasing files", func(t *testing.T) {
		p, r := fixture(t, valid, "spec.gz")
		second := map[string]any{}
		for k, v := range r {
			second[k] = v
		}
		second["id"] = "second"
		second["file"] = "sub/../spec.gz"
		second["url"] = "https://docs.cpanel.net/second.json"
		_ = os.Mkdir(filepath.Join(filepath.Dir(p), "sub"), 0700)
		writeManifest(t, p, []map[string]any{r, second})
		if _, err := Load(p, DefaultLimits()); err == nil {
			t.Fatal("two sources sharing a normalized file accepted")
		}
	})
	t.Run("retrieval time", func(t *testing.T) {
		p, r := fixture(t, valid, "spec.gz")
		r["retrieved_at"] = "not a timestamp"
		writeManifest(t, p, []map[string]any{r})
		if _, err := Load(p, DefaultLimits()); err == nil {
			t.Fatal("invalid retrieval timestamp accepted")
		}
	})
}

func TestLoadReferencesRespectOpenAPIDataPositions(t *testing.T) {
	raw := `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"Thing":{"type":"string"},"Container":{"properties":{"example":{"$ref":"#/components/schemas/Thing"},"x-property":{"$ref":"#/components/schemas/Thing"}},"example":{"$ref":"https://docs.cpanel.net/not-reference.json"},"default":{"$ref":null},"enum":[{"$ref":false}],"x-data":{"$ref":"../not-reference.json"}}},"examples":{"RealExample":{"$ref":"#/components/examples/Value"},"Value":{"value":{"$ref":"https://docs.cpanel.net/data.json"}}}},"x-data":{"$ref":"https://docs.cpanel.net/vendor.json"}}`
	p, _ := fixture(t, raw, "spec.gz")
	b, err := Load(p, DefaultLimits())
	if err != nil {
		t.Fatal(err)
	}
	if len(References(b.Documents["spec"])) != 2 {
		t.Fatalf("incorrect real refs: %#v", References(b.Documents["spec"]))
	}
	for _, raw := range []string{`{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"S":{"properties":{"example":{"$ref":"#/missing"}}}}}}`, `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"examples":{"E":{"$ref":"#/missing"}}}}}`} {
		p, _ := fixture(t, raw, "spec.gz")
		if _, err := Load(p, DefaultLimits()); err == nil {
			t.Fatal("actual schema/example reference ignored")
		}
	}
}

const refSiblingFixture = `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"T":{"type":"string"},"Alias":{"$ref":"#/components/schemas/T","items":{"$ref":"https://example.com/ignored.json"},"properties":{"example":{"$ref":"https://docs.cpanel.net/ignored.json"}},"discriminator":{"mapping":{"ignored":"https://example.com/also-ignored.json"}}}},"parameters":{"T":{"name":"q","in":"query","schema":{"type":"string"}},"Alias":{"$ref":"#/components/parameters/T","schema":{"$ref":"https://example.com/parameter-ignored.json"}}}}}`

func TestLoadReferenceSiblingsRemainRawData(t *testing.T) {
	p, _ := fixture(t, refSiblingFixture, "spec.gz")
	b, err := Load(p, DefaultLimits())
	if err != nil {
		t.Fatal(err)
	}
	v, err := Pointer(b.Documents["spec"], "/components/schemas/Alias/items/$ref")
	if err != nil || v != "https://example.com/ignored.json" {
		t.Fatal("ignored siblings not retained")
	}
}
func TestLoadXPrefixedComponentReferences(t *testing.T) {
	for _, kind := range []string{"responses", "callbacks"} {
		t.Run(kind, func(t *testing.T) {
			raw := `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"` + kind + `":{"x-ValidComponent":{"$ref":"#/missing"}}}}`
			p, _ := fixture(t, raw, "spec.gz")
			if _, err := Load(p, DefaultLimits()); err == nil {
				t.Fatal("x-prefixed component reference ignored")
			}
		})
	}
}
func ambiguousFileFixture(t *testing.T) (string, []map[string]any) {
	t.Helper()
	p, r := fixture(t, valid, "spec.gz")
	records := []map[string]any{r}
	for i, file := range []string{"common.json.gz", "common.json"} {
		q, ref := fixture(t, fmt.Sprintf(`{"Thing":{"type":"string","description":"%d"}}`, i), file)
		ref["kind"] = "reference"
		ref["id"] = fmt.Sprintf("reference%d", i)
		ref["url"] = fmt.Sprintf("https://docs.cpanel.net/reference%d", i)
		data, _ := os.ReadFile(filepath.Join(filepath.Dir(q), file))
		if err := os.WriteFile(filepath.Join(filepath.Dir(p), file), data, 0600); err != nil {
			t.Fatal(err)
		}
		records = append(records, ref)
	}
	return p, records
}
func TestLoadRejectsCompressedFilenameIdentityCollisions(t *testing.T) {
	for _, reverse := range []bool{false, true} {
		t.Run(fmt.Sprint(reverse), func(t *testing.T) {
			p, records := ambiguousFileFixture(t)
			if reverse {
				records[1], records[2] = records[2], records[1]
			}
			writeManifest(t, p, records)
			b, err := Load(p, DefaultLimits())
			if err == nil || b.Records != nil || b.Documents != nil {
				t.Fatal("ambiguous exact/compressed file identities accepted")
			}
		})
	}
}
func TestPointerRejectsNonDecimalArrayIndices(t *testing.T) {
	d := model.Object{"a": []any{"zero", "one"}}
	for _, pointer := range []string{"/a/+1", "/a/-0", "/a/01", "/a/1.0", "/a/١"} {
		if _, err := Pointer(d, pointer); err == nil {
			t.Fatalf("accepted nondecimal index %s", pointer)
		}
	}
	for _, pointer := range []string{"/a/0", "/a/1"} {
		if _, err := Pointer(d, pointer); err != nil {
			t.Fatalf("rejected decimal index %s: %v", pointer, err)
		}
	}
}

func TestLoadXPrefixedOperationCallbackIsNamedDeclaration(t *testing.T) {
	raw := `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{"/ping":{"post":{"responses":{"200":{"description":"OK"},"x-data":{"$ref":"https://example.com/raw-data"}},"callbacks":{"x-ActualCallback":{"$ref":"#/missing"}}}}}}`
	p, _ := fixture(t, raw, "spec.gz")
	if _, err := Load(p, DefaultLimits()); err == nil {
		t.Fatal("arbitrary x-prefixed callback name ignored")
	}
}
func TestResolveRejectsSyntheticAliasCollision(t *testing.T) {
	_, records := ambiguousFileFixture(t)
	b := Bundle{Documents: map[string]model.Object{"spec": {}, "reference0": {}, "reference1": {}}}
	for _, r := range records {
		b.Records = append(b.Records, model.Object(r))
	}
	if _, _, err := ResolveReference(b, "spec", "common.json#/Thing"); err == nil {
		t.Fatal("ambiguous local identity resolved by record order")
	}
}

func TestLoadPathItemReferenceSiblingsRemainDeclarations(t *testing.T) {
	raw := `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{"/target":{},"/alias":{"$ref":"#/paths/~1target","parameters":[{"$ref":"#/missing"}]}}}`
	p, _ := fixture(t, raw, "spec.gz")
	if _, err := Load(p, DefaultLimits()); err == nil {
		t.Fatal("Path Item sibling parameter declaration was ignored")
	}
}

func TestLoadUnsupportedRefDoesNotSuppressDeclarations(t *testing.T) {
	for _, raw := range []string{
		`{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{"/ping":{"get":{"$ref":"#/components/schemas/T","responses":{"200":{"$ref":"#/missing"}}}}},"components":{"schemas":{"T":{"type":"string"}}}}`,
		`{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"responses":{"R":{"description":"OK","content":{"application/json":{"$ref":"#/components/schemas/T","schema":{"$ref":"#/missing"}}}}},"schemas":{"T":{"type":"string"}}}}`,
		`{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"requestBodies":{"R":{"content":{"application/json":{"encoding":{"field":{"$ref":"#/components/schemas/T","headers":{"H":{"$ref":"#/missing"}}}}}}}},"schemas":{"T":{"type":"string"}}}}`,
	} {
		p, _ := fixture(t, raw, "spec.gz")
		if _, err := Load(p, DefaultLimits()); err == nil {
			t.Fatal("unsupported $ref suppressed genuine nested declaration")
		}
	}
}
func TestLoadUnsupportedRefFieldsRemainRawData(t *testing.T) {
	raw := `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{"/ping":{"get":{"$ref":"https://example.com/operation-data","responses":{"200":{"description":"OK","content":{"application/json":{"$ref":"https://example.com/media-data","schema":{"type":"string"},"encoding":{"field":{"$ref":"https://example.com/encoding-data"}}}}}}}}}}`
	p, _ := fixture(t, raw, "spec.gz")
	if _, err := Load(p, DefaultLimits()); err != nil {
		t.Fatal(err)
	}
}
