# 20. Brand-name mentions in AI answers

An AI answer can name your business without linking to your website. **Project → AI visibility → Brand-name mentions** checks the public name separately from the domain-based results above it.

## Set up your name

1. Connect DataForSEO in **Integrations** and set workspace spending limits. This is a paid provider feature.
2. Open **Brand settings**. Enter the name your customers recognise, up to 120 characters. Prefer a distinctive name; a common name can match unrelated businesses.
3. Choose the Google country and language. ChatGPT results always cover the United States in English, regardless of this choice.
4. Leave weekly checks off for manual control, or explicitly tick the weekly paid-check option.
5. Select **Save brand settings**. Saving alone makes no provider request. With weekly checks enabled, the next eligible cron run starts a check and subsequent checks run at least seven days after the last attempt.
6. Select **Check brand name** for a manual check. It uses four requests: a count and up to 25 answer examples for each platform. The current estimate is about **$0.452**; actual provider charges are recorded under spending, and workspace budgets apply to every request.

Cron processes one request per job. Refreshing this page and reading saved results do not start another paid lookup. The same check cannot be queued twice while it is pending.

## Understand the results

- **Name mentions** counts provider word matches in answer text. Matching can contain additional words; it is not an exact-phrase guarantee.
- **AI search volume** is the provider's estimated monthly question volume, not visits or sales. “Unavailable” means the provider did not supply it.
- **Answer examples** contain a short excerpt, question, provider response date and cited sources. A brand mention can have **no project-domain citation**. If your domain appears among the sources, its source rank is shown separately.
- **History** shows the latest 12 attempts for the same name, market and project domain. The charts use the latest completed check per UTC day. Failed and missing checks are not zero. Changing the name or market selects a separate series; previous checks remain stored.

Read the excerpts to reject namesakes. These are samples from DataForSEO's ChatGPT and Google AI database, not a complete record of every AI conversation, website, news article or social mention. Gemini and Perplexity are not included.

**Do not add brand-name counts to domain counts.** The same answer can match both. Compare each series with itself and consider other evidence before attributing a change to SEO work.

## Failures and stopping checks

Provider or budget failures retain the attempt's status and do not replace a previous completed result with partial data. A failed check is not automatically retried. Weekly opt-in can start a fresh check after seven days; switch it off to stop that schedule.

If a process stops during a request, SEOcc records the step as processing before sending it and will not automatically resend it. Review **System → Jobs** and provider spending before requesting another paid check. A pending check blocks new checks for 24 hours. After that, an explicit new check supersedes the abandoned one, preventing its late queued steps from making further requests. A request already sent may still be billed.

Untick weekly checks to stop scheduled work. Clear the name and save to stop tracking entirely while retaining stored history. Changed settings or revoked project access stop further queued requests; an already sent request may still be billed.

## Ask AI and ChatGPT

Ask AI can use the saved name results through `ai_visibility`. For example: “Compare Acme Outdoor's completed name-mention checks, identify any namesakes in the excerpts, and distinguish mentions from website citations.” The tool reads saved data and does not spend provider money. External MCP tokens need the existing **rankings:read** scope and access to the project.
