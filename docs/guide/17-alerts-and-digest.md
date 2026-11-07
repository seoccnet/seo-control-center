# Alerts and weekly digest

Under **Your account → Notifications**, choose the alerts and digest you want, mute individual projects and review alerts from the last 30 days. Your installation needs configured e-mail delivery under **System → E-mail**.

## What triggers an alert

SEOcc checks stored data hourly for keywords leaving the top 10, weekly clicks falling by at least 30% from a baseline of at least 100 clicks, new site-audit errors, lost strong backlinks and low DataForSEO budget or balance.

After a rank check, it reports **results-page changes** that matter: an AI Overview appearing for a keyword where you rank in the top 10, you leaving the top 10, or a tracked competitor entering the top 10 above you. See *Research → Results-page changes* for all changes.

It also reports changes in Google's indexing verdict or selected canonical after two successful [URL inspections](23-google-indexing.md). Initial results and failed checks do not create change alerts. Google returns its stored index evidence; a change is a reason to investigate, not a confirmed technical cause. The indexing/canonical alert type can be muted like other project alerts.

Alerts do not start paid lookups. They depend on current imported data, completed audits and stored backlink results. Each event is sent once. You receive project alerts only for projects you can see; budget alerts require integration-management permission.

## Your Monday digest

The weekly digest brings together the latest complete week's clicks, top-10 keywords, site health, winners and losers, opportunities, alerts and a report link. It covers up to ten visible projects per workspace and is sent on Mondays in UTC.

An AI summary is optional and off by default. It uses the workspace's configured assistant and counts against its AI budget. Without a working assistant or sufficient budget, the digest goes out without that summary.

## Control what you receive

Turn off individual alert types, mute a project's notifications or disable the digest in your account settings. Alerts stay available in Notifications even when e-mail delivery is not configured; enabling mail later does not send an old backlog.

## Tune project alert sensitivity

Project editors can expand a project under **Project alert sensitivity** and choose:

- Traffic drop: 1–100%, default 30%.
- Minimum clicks in the previous seven days: 1–1,000,000, default 100.
- Ranking cutoff: top 1–100, default top 10. An alert means a keyword left this group between checks.
- Minimum lost-link domain rank: 0–100, default 30. Unknown domain ranks are excluded.

These settings apply to all recipients of the project, starting with the next hourly scan. Existing alerts and their once-per-event history remain intact. Audit-error and budget rules are unchanged.

## Send project alerts to your team

With integration-management permission, open **Team alert delivery** for a project.

1. For Slack, create an incoming webhook in your Slack app settings and select its channel. Choose **Slack incoming webhook** in SEOcc and paste that URL.
2. For another service, choose **JSON endpoint** and paste a public HTTPS URL accepting POST requests. Optionally supply a bearer token. SEOcc does not follow redirects or connect to private network addresses.
3. Enable delivery and save. The next new project alert is sent by cron. **Recent delivery attempts** shows whether the endpoint accepted it. Past alerts are not replayed.

The generic JSON payload contains `event` (`scc.alert`), `id`, `type`, `title`, `lines`, `url` and `created_at` (UTC). The `X-SCC-Event-ID` header repeats the event ID for receiver-side deduplication. Use authentication on your endpoint and keep webhook URLs secret.

URLs and tokens are encrypted and are never redisplayed. Leave them blank to keep them; clear the token or remove the destination to revoke it. Saving changes invalidates deliveries already queued for the old configuration.

Team delivery is independent of personal email mutes. It covers project alerts, not workspace budget alerts or weekly digests. SEOcc rechecks the configuring user's current permission before sending. Each event has one delivery attempt: after an interrupted or uncertain transfer, check the receiving service before taking action. Automatic retries could send duplicates. Live Slack or endpoint setup requires your own verification.
