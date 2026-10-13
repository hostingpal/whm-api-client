package cli

import (
	"context"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/contract"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/mapbuild"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/openapi"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func TestDiscriminatorResolutionAcrossPipeline(t *testing.T) {
	raw := `{"openapi":"3.0.2","info":{"title":"synthetic","version":"1"},"paths":{"/valid":{"get":{"responses":{"200":{"description":"ok","content":{"application/json":{"schema":{"$ref":"#/components/schemas/Base"}}}}}}}},"components":{"schemas":{"Base":{"discriminator":{"propertyName":"kind","mapping":{"local":"Child.V1","external":"external.json#/Remote"}}},"Child.V1":{"$ref":"#/components/schemas/Leaf"},"Leaf":{"type":"string"}}}}`
	remote := `{"Remote":{"type":"integer"}}`
	dir := outputFixture(t)
	records := []model.Object{}
	for _, file := range []string{"source.json", "external.json"} {
		data, kind, id := raw, "openapi", "source.json"
		if file == "external.json" {
			data, kind, id = remote, "reference", "external"
		}
		hash := fmt.Sprintf("%x", sha256.Sum256([]byte(data)))
		records = append(records, model.Object{"id": id, "kind": kind, "api_family": "whm-api-1", "file": file, "url": "https://api.docs.cpanel.net/" + file, "sha256": hash, "stored_sha256": hash, "bytes": len(data), "stored_bytes": len(data), "compression": "none", "retrieved_at": "2026-10-13T00:00:00Z"})
		if err := os.WriteFile(filepath.Join(dir, file), []byte(data), 0600); err != nil {
			t.Fatal(err)
		}
	}
	manifestPath := filepath.Join(dir, "manifest.json")
	man, _ := json.Marshal(model.Object{"schema_version": 1, "hash_algorithm": "sha256", "snapshot_id": "synthetic", "sources": records})
	if err := os.WriteFile(manifestPath, man, 0600); err != nil {
		t.Fatal(err)
	}
	check := func(t *testing.T, b source.Bundle) {
		t.Helper()
		analysis, err := openapi.Analyze(b)
		if err != nil || len(analysis.DiscriminatorTargets) != 2 {
			t.Fatalf("analyzer targets: %v %v", analysis.DiscriminatorTargets, err)
		}
		m, err := mapbuild.Build(b, nil, nil)
		if err != nil {
			t.Fatal(err)
		}
		loc := m.Operations[0].Variants[0].OperationLocator
		before, err := contract.Hash(m, loc)
		if err != nil {
			t.Fatal(err)
		}
		leaf, err := source.Pointer(m.Documents["source.json"], "/components/schemas/Leaf")
		if err != nil {
			t.Fatal(err)
		}
		leaf.(map[string]any)["type"] = "number"
		after, err := contract.Hash(m, loc)
		if err != nil || before == after {
			t.Fatalf("transitive local contract change lost: %v", err)
		}
		remoteDoc := m.Documents[b.Records[1]["id"].(string)]
		remoteDoc["Remote"].(map[string]any)["type"] = "boolean"
		externalAfter, err := contract.Hash(m, loc)
		if err != nil || after == externalAfter {
			t.Fatalf("external target contract change lost: %v", err)
		}
	}
	t.Run("offline", func(t *testing.T) {
		b, err := source.Load(manifestPath, source.Limits{})
		if err != nil {
			t.Fatal(err)
		}
		check(t, b)
	})
	t.Run("analyzer and contract", func(t *testing.T) {
		var doc, ref model.Object
		if err := json.Unmarshal([]byte(raw), &doc); err != nil {
			t.Fatal(err)
		}
		if err := json.Unmarshal([]byte(remote), &ref); err != nil {
			t.Fatal(err)
		}
		check(t, source.Bundle{Records: records, Documents: map[string]model.Object{"source.json": doc, "external": ref}})
	})
	t.Run("refresh", func(t *testing.T) {
		// Start without the external pin: refresh must discover it, but never fetch Child.V1.
		seedData := `{"openapi":"3.0.2","info":{"title":"seed","version":"1"},"paths":{}}`
		seedRecord := model.Object{}
		for key, value := range records[0] {
			seedRecord[key] = value
		}
		seedHash := fmt.Sprintf("%x", sha256.Sum256([]byte(seedData)))
		seedRecord["sha256"], seedRecord["stored_sha256"] = seedHash, seedHash
		seedRecord["bytes"], seedRecord["stored_bytes"] = len(seedData), len(seedData)
		if err := os.WriteFile(filepath.Join(dir, "source.json"), []byte(seedData), 0600); err != nil {
			t.Fatal(err)
		}
		seed, _ := json.Marshal(model.Object{"schema_version": 1, "hash_algorithm": "sha256", "snapshot_id": "synthetic", "sources": []model.Object{seedRecord}})
		if err := os.WriteFile(manifestPath, seed, 0600); err != nil {
			t.Fatal(err)
		}
		calls := []string{}
		client := &http.Client{Transport: roundTripFunc(func(req *http.Request) (*http.Response, error) {
			calls = append(calls, req.URL.Path)
			data := raw
			switch req.URL.Path {
			case "/source.json":
			case "/external.json":
				data = remote
			default:
				return nil, fmt.Errorf("unexpected discriminator fetch %s", req.URL)
			}
			return &http.Response{StatusCode: 200, Header: http.Header{}, Body: io.NopCloser(strings.NewReader(data)), Request: req}, nil
		})}
		result, err := source.Refresh(context.Background(), source.RefreshRequest{ManifestPath: manifestPath, SnapshotDir: filepath.Join(t.TempDir(), "new")}, client)
		if err != nil {
			t.Fatal(err)
		}
		b, err := source.Load(result, source.Limits{})
		if err != nil {
			t.Fatal(err)
		}
		if len(b.Records) != 2 || len(calls) != 2 {
			t.Fatalf("wrong pinning/fetches: %d %v", len(b.Records), calls)
		}
		check(t, b)
	})
}
