package source

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"math/big"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"gopkg.in/yaml.v3"
)

func decode(raw []byte, filename string, l Limits) (model.Object, error) {
	var value any
	var err error
	trim := bytes.TrimSpace(raw)
	if strings.HasSuffix(strings.TrimSuffix(filename, ".gz"), ".json") || (len(trim) > 0 && (trim[0] == '{' || trim[0] == '[')) {
		if !utf8.Valid(raw) {
			return nil, fmt.Errorf("source JSON is not valid UTF-8")
		}
		d := json.NewDecoder(bytes.NewReader(raw))
		d.UseNumber()
		value, err = jsonValue(d, 0, l.MaxDepth)
		if err == nil {
			_, e := d.Token()
			if e != io.EOF {
				err = fmt.Errorf("trailing JSON data")
			}
		}
	} else {
		d := yaml.NewDecoder(bytes.NewReader(raw))
		var doc yaml.Node
		if err = d.Decode(&doc); err == nil {
			var extra yaml.Node
			if e := d.Decode(&extra); e != io.EOF {
				err = fmt.Errorf("multiple YAML documents")
			}
			if err == nil {
				state := yamlState{limits: l, active: map[*yaml.Node]bool{}}
				value, err = state.value(&doc, 0, false)
			}
		}
	}
	if err != nil {
		return nil, err
	}
	obj, ok := value.(map[string]any)
	if !ok {
		return nil, fmt.Errorf("document root must be an object")
	}
	return model.Object(obj), nil
}
func jsonValue(d *json.Decoder, depth, max int) (any, error) {
	if depth > max {
		return nil, fmt.Errorf("JSON nesting limit exceeded")
	}
	tok, err := d.Token()
	if err != nil {
		return nil, err
	}
	delim, ok := tok.(json.Delim)
	if !ok {
		return tok, nil
	}
	switch delim {
	case '{':
		obj := map[string]any{}
		for d.More() {
			key, err := d.Token()
			if err != nil {
				return nil, err
			}
			s, ok := key.(string)
			if !ok {
				return nil, fmt.Errorf("invalid JSON key")
			}
			if _, exists := obj[s]; exists {
				return nil, fmt.Errorf("duplicate JSON key %q", s)
			}
			v, err := jsonValue(d, depth+1, max)
			if err != nil {
				return nil, err
			}
			obj[s] = v
		}
		_, err = d.Token()
		return obj, err
	case '[':
		arr := []any{}
		for d.More() {
			v, err := jsonValue(d, depth+1, max)
			if err != nil {
				return nil, err
			}
			arr = append(arr, v)
		}
		_, err = d.Token()
		return arr, err
	default:
		return nil, fmt.Errorf("unexpected JSON delimiter")
	}
}

type yamlState struct {
	limits   Limits
	expanded int
	active   map[*yaml.Node]bool
}

func (s *yamlState) value(n *yaml.Node, depth int, expanding bool) (any, error) {
	if depth > s.limits.MaxDepth {
		return nil, fmt.Errorf("YAML nesting limit exceeded")
	}
	if s.active[n] {
		return nil, fmt.Errorf("cyclic YAML alias")
	}
	s.active[n] = true
	defer delete(s.active, n)
	if expanding {
		s.expanded++
		if s.expanded > s.limits.MaxAliasNodes {
			return nil, fmt.Errorf("YAML alias expansion limit exceeded")
		}
	}
	switch n.Kind {
	case yaml.DocumentNode:
		if len(n.Content) != 1 {
			return nil, fmt.Errorf("invalid YAML document")
		}
		return s.value(n.Content[0], depth, expanding)
	case yaml.AliasNode:
		if n.Alias == nil {
			return nil, fmt.Errorf("invalid YAML alias")
		}
		return s.value(n.Alias, depth+1, true)
	case yaml.MappingNode:
		if n.Tag != "!!map" {
			return nil, fmt.Errorf("custom YAML mapping tag")
		}
		obj := map[string]any{}
		for i := 0; i < len(n.Content); i += 2 {
			k := n.Content[i]
			if k.Kind != yaml.ScalarNode || k.Tag != "!!str" {
				return nil, fmt.Errorf("YAML keys must be strings")
			}
			if _, exists := obj[k.Value]; exists {
				return nil, fmt.Errorf("duplicate YAML key %q", k.Value)
			}
			// Mapping keys are nodes too when an alias is expanded.
			if _, err := s.value(k, depth+1, expanding); err != nil {
				return nil, err
			}
			v, err := s.value(n.Content[i+1], depth+1, expanding)
			if err != nil {
				return nil, err
			}
			obj[k.Value] = v
		}
		return obj, nil
	case yaml.SequenceNode:
		if n.Tag != "!!seq" {
			return nil, fmt.Errorf("custom YAML sequence tag")
		}
		arr := []any{}
		for _, child := range n.Content {
			v, err := s.value(child, depth+1, expanding)
			if err != nil {
				return nil, err
			}
			arr = append(arr, v)
		}
		return arr, nil
	case yaml.ScalarNode:
		switch n.Tag {
		case "!!str":
			return n.Value, nil
		case "!!null":
			return nil, nil
		case "!!bool":
			v, err := strconv.ParseBool(strings.ToLower(n.Value))
			return v, err
		case "!!int":
			v := new(big.Int)
			if _, ok := v.SetString(strings.ReplaceAll(n.Value, "_", ""), 0); !ok {
				if _, ok = v.SetString(strings.ReplaceAll(n.Value, "_", ""), 10); !ok {
					return nil, fmt.Errorf("invalid YAML integer")
				}
			}
			return json.Number(v.String()), nil
		case "!!float":
			v := strings.ReplaceAll(n.Value, "_", "")
			if strings.HasPrefix(v, ".") {
				v = "0" + v
			}
			if strings.HasPrefix(v, "-.") {
				v = "-0" + v[1:]
			}
			if strings.HasPrefix(v, "+.") {
				v = "0" + v[1:]
			}
			v = strings.TrimPrefix(v, "+")
			if strings.HasSuffix(v, ".") {
				v += "0"
			}
			if !json.Valid([]byte(v)) {
				return nil, fmt.Errorf("YAML float is not a JSON number")
			}
			return json.Number(v), nil
		default:
			return nil, fmt.Errorf("unsupported YAML tag %s", n.Tag)
		}
	default:
		return nil, fmt.Errorf("unsupported YAML node")
	}
}
