package phpgen

import (
	"fmt"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

func formRecordShape(record *effective.FormRecord) string {
	var shapes []string
	for _, variant := range record.Variants {
		tag := record.Discriminator
		if record.Untagged {
			tag += "?"
		}
		lines := []string{"array{", "    " + tag + ": " + phpString(variant.Tag) + ","}
		for _, field := range variant.Fields {
			name := field.Name
			if !field.Required {
				name += "?"
			}
			lines = append(lines, "    "+name+": "+field.Type+",")
		}
		shapes = append(shapes, strings.Join(append(lines, "}"), "\n"))
	}
	return strings.Join(shapes, "|")
}

func renderFormRecord(w *writer, d *effective.FormRecord) {
	w.line("        'form_record' => [")
	w.line("            'php' => " + phpString(d.PHP) + ",")
	w.line("            'discriminator' => " + phpString(d.Discriminator) + ",")
	w.line(fmt.Sprintf("            'required' => %t,", d.Required))
	if d.Untagged {
		w.line("            'untagged' => true,")
	}
	if len(d.ChangeParams) > 0 {
		w.line("            'change_params' => " + listLiteral(d.ChangeParams, "            ", len("            'change_params' => ")) + ",")
	}
	w.line("            'variants' => [")
	for _, v := range d.Variants {
		w.line("                " + phpString(v.Tag) + " => [")
		for _, f := range v.Fields {
			w.line(fmt.Sprintf("                    %s => ['type' => %s, 'required' => %t],", phpString(f.Name), phpString(f.Type), f.Required))
		}
		w.line("                ],")
	}
	w.line("            ],")
	w.line("        ],")
}
