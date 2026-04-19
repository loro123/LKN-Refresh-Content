# Content-to-CSV Prompt

Copy everything below the line and paste it into Claude (or any LLM). Then attach your Word doc / file or paste your content after it.

---

Convert the attached document(s) into a CSV file for WordPress import. Each document or distinct piece of content becomes one row.

**CSV columns (this exact order, this exact header row):**

```
post_title,post_name,post_content,post_type,post_date,post_status,h1_tag
```

**Column definitions:**

- `post_title` — (required) the page/post title, taken from the document's main heading
- `post_name` — (required) URL slug derived from the title: lowercase, hyphens for spaces, no special characters (e.g. "Kitchen Remodeling Services" → "kitchen-remodeling-services")
- `post_content` — the full body as clean HTML (`<h2>`, `<h3>`, `<p>`, `<ul>`, `<ol>`, `<li>`, `<strong>`, `<em>`, `<a>`). Strip the H1 from the body (it goes in h1_tag). Remove any Word/Office junk (mso-*, class names, inline styles). Do not add content that wasn't in the original.
- `post_type` — `page` or `post`. Default to `page` unless I say otherwise.
- `post_date` — optional. Format: `YYYY-MM-DD HH:MM:SS`. Leave empty (`""`) for immediate publish. Future dates auto-schedule in WordPress.
- `post_status` — optional. Values: `publish`, `draft`, `pending`, `future`, `private`. Leave empty (`""`) to use default. Future-dated rows auto-switch to `future`.
- `h1_tag` — the title as an H1 tag: `<h1>Kitchen Remodeling Services</h1>`

**CSV formatting (critical — the import will break otherwise):**

- Quote every field with double quotes
- Escape any double quotes inside a field by doubling them (`"` → `""`)
- Multi-line HTML inside quotes is fine — preserve line breaks
- Empty optional fields = `""`
- Plain UTF-8, no BOM

**Example row:**

```csv
post_title,post_name,post_content,post_type,post_date,post_status,h1_tag
"Kitchen Remodeling Services","kitchen-remodeling-services","<h2>Transform Your Kitchen</h2>

<p>We specialize in full kitchen remodels including cabinetry, countertops, and flooring.</p>

<h3>Our Process</h3>

<ul>
<li>Free in-home consultation</li>
<li>3D design mockup</li>
<li>Professional installation</li>
</ul>

<p>Serving the greater Miami area since 2015.</p>","page","","","<h1>Kitchen Remodeling Services</h1>"
```

**If I give scheduling instructions** (e.g. "schedule weekly starting May 1"), calculate each row's `post_date` accordingly and leave `post_status` empty.

Now convert the content I've provided:
