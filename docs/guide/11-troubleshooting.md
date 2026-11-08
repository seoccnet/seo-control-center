# 11. Troubleshooting

## Where to look first

**System health** checks the installation and shows each problem with a hint:

- PHP and hosting;
- the database;
- storage;
- encryption;
- backups;
- cron;
- the job queue.

**System → Jobs** lists background jobs that failed, with the reason. After fixing the cause, click **Retry**, or **Dismiss** the job if it no longer matters.

## Common problems

| Symptom | Cause and fix |
|---|---|
| Nothing updates: no imports, no rank checks | Cron is not running. *System health → Cron* shows when it last ran. Check the cron command and the PHP path ([chapter 2, step 5](02-installation.md)). |
| *Cron* warning although cron is set every 5 minutes | Add `CRON_INTERVAL_MINUTES=5` to `.env` |
| "Needs reconnection" for Google | Access was withdrawn at Google. Connect again under *Integrations*. If it happens every 7 days, your Google app is still in *Testing*; publish it ([chapter 5](05-google-search-console.md)). |
| DataForSEO "refused access" | Usually an unverified account or no credit at DataForSEO. Fix it there, then click *Test connection*. |
| Rank checks stop with a budget message | The daily or monthly budget is used up. Raise it under *Integrations → DataForSEO*, or wait for the next day or month. |
| "is turned off on this installation" | An administrator listed that feature in `DISABLED_FEATURES` in `.env`. Remove it to turn the feature on. Your data stays available meanwhile. |
| Update refused: "not a valid package" / "signature" | Use the original, unmodified update package (`scc-<version>.zip`, not the install package) from the project\'s GitHub releases |
| A page shows "Error 419" | The form expired, for example after a long pause. Reload the page and try again. |
| Site shows "being updated" for a long time | An update stopped midway. Sign in and open *System → Updates*: it shows the failed step. Install the same package again. |

## Two-factor sign-in

- **Lost your phone?** Sign in with one of your **recovery codes** instead of the app code.
- **No codes left?** Another administrator can help, or contact support.

## Support

Please include, in a [GitHub issue](https://github.com/seoccnet/seo-control-center/issues):

1. a **support bundle** (*System health → Download support bundle*). It contains health checks, versions and recent errors, with e-mail addresses and tokens removed, and never passwords, keys or SEO data;
2. what you did, what you expected, and what happened.
