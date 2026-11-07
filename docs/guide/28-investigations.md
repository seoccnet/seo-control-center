# 28. Investigations

An investigation keeps the evidence for one question together: what you are trying to find out, the dated sources you used, the checks you made, what the data cannot show, and your conclusion. Open **Research → Investigations** in a project.

## Start one

Enter what you are investigating (for example *Why did sunflower seed traffic drop in October?*) and, if you like, the question or hypothesis. Project editors can start and change investigations; everyone who sees the project can read them.

You can also start one from a **Why they outrank you** analysis: under the result, choose **Start an investigation with it**.

## Add evidence

Each entry is one of four kinds:

- **Source:** where evidence comes from, with the date it was observed: an analysis, a research lookup, a page or any URL.
- **Check:** something you verified, for example "Compared October with September on the same weekdays".
- **Limitation:** what the data cannot show, for example "GA4 is not linked" or "two competing pages blocked the reader".
- **Note:** anything else worth keeping.

On the investigation page:

- **Analysis** adds a finished *Why they outrank you* analysis with its sources (the results page and each page it read), the checks it made (format, subtopic coverage, bottleneck, internal links) and its limitations (unreadable pages, low confidence, no owner page, no AI explanation, structure only).
- **Lookup on file** adds a stored research lookup from *Research on file* as a dated source, with its market, inputs and whether it is still current.
- The form adds an entry by hand, optionally with a URL and the date the evidence was observed (not in the future).

Adding never buys data: it records what SEO Control Center already stored. An investigation holds up to 300 entries; **Remove** takes one out.

## Conclude

Under **Edit, conclude or archive**, change the title or question, write the conclusion and set the status:

- **Open** while you are still collecting evidence;
- **Concluded** once you have a conclusion (it is required);
- **Archived** when it no longer matters.

If someone else saved the investigation in the meantime, your change is refused instead of overwriting theirs: reload the page and save again.

## With an AI assistant

Over AI access (MCP), an assistant can read investigations (`investigations_list`, `investigation_get`, with *Keywords and metrics*) before it starts new research, so it reuses what was already found. With the box *Keep research notebooks (investigations)* ticked on the token, it can also start or update one (`investigation_save`) and add entries or a finished analysis (`investigation_add`). Entries it adds show "via <token name>". See [AI access (MCP)](08-ai-access-mcp.md).
