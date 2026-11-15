# 29. Site migration

*Improve → Site migration* helps you move a site without losing its search traffic: a new domain, a switch to HTTPS on a new host, a new shop system, or a new URL structure on the same domain. A plan lists every old address that must keep working, where it should redirect, whether the redirect works, and after launch whether each page kept its clicks.

## Create a plan

Enter a name, the old site (for example `https://www.example.com`), the new site, and optionally the launch date. Use the same address for both for a restructure on one domain. The old or the new site must be on the project's domain. Both must use HTTPS. A project keeps up to 10 plans.

Creating and changing plans needs the *Edit projects* permission. Everyone who can see the project can open its plans and download the redirects.

## 1. Addresses

**Old addresses** are added from one of three sources, and each import adds to the plan (up to 20,000 addresses):

- **Search Console pages**: every page of the old site with impressions in the last 16 months. This is usually the best list: it holds exactly the pages that earn search traffic.
- **Latest site audit**: the pages the last completed audit fetched.
- **A list**: full addresses or paths, one per line. A pasted CSV works too; its first column is read.

Addresses on another host are skipped. Each old address shows its Search Console clicks of the 28 days before launch (or the latest 28 days before a launch date is set), so the list starts with the pages that matter most.

**New addresses** are the pages of the new site, pasted as a list (a staging site's sitemap or URL export works well) or taken from the latest site audit when the new site is already live. Saving replaces the previous list. Up to 50,000 addresses.

## 2. Redirects

**Suggest redirects** matches each old address with a new one in the background. In order of confidence:

| Match | Confidence | Meaning |
|---|---|---|
| Path | 100% | The same path on the new site. |
| Path | 95% | The same path once letter case, a trailing slash, `index.html` and `.html` or `.php` endings are ignored. |
| Slug | 85% | The same last path segment, such as `/rain-barrel-200l`, found on exactly one new page. |
| Similar | 48–80% | The new path sharing most of the old path's words. |
| Parent | 30% | The closest parent folder that exists on the new site. |

Old addresses with no candidate get no target. They are never sent to the home page, because search engines treat a mass redirect to the home page like a missing page. Review the list, starting with *No target*, and type a different address into any row to choose it by hand. Choices made by hand are kept when you suggest redirects again.

**Download** the redirects as:

- **CSV**: every old address with its target, suggestion, match, clicks and last test result;
- **Apache .htaccess**: `Redirect 301 /old-path https://new-site/new-path` lines for the old site;
- **nginx**: `location = /old-path { return 301 …; }` lines for the old site's server block.

Old addresses with a query string (`?id=12`) cannot be written as a simple rule; the files say how many were left out, and the CSV lists them.

## Test the redirects

**Test redirects** requests every old address once in the background, needs the *Run analyses* permission and the Site audit feature, and can run four times an hour per project. It uses the site audit's crawler: HTTPS only, robots.txt respected, redirects reported rather than followed. Each address gets one result:

| Result | Meaning |
|---|---|
| Redirects correctly | 301 or 308 to the chosen address, which loads. |
| Temporary redirect | 302, 303 or 307. Use a permanent redirect so search engines move the old page's signals. |
| Redirects elsewhere | A permanent redirect, but not to the chosen address. |
| Redirect chain | The redirect leads to another redirect. Point it straight at the final address. |
| Target broken | The chosen address returns an error. |
| Not redirected yet | The old address still answers with its own page. Expected before launch. |
| Gone (404) | Not redirected and missing. These lose their traffic. |
| Test failed / Not tested | No answer, or not tested (outside the plan's sites, not HTTPS, or disallowed by robots.txt). |

*To fix* lists everything that needs attention. Run the test again on launch day and a few days later.

## 3. Results after launch

With a launch date, the plan compares each old address's Search Console clicks in the 28 days before launch with the clicks of its new address since launch, scaled to 28 days and capped at 90 days after launch. Results appear once Search Console has seven days of data since launch. The largest losses are listed first.

Some change is normal in the first weeks while search engines process the redirects. Seasonality, other changes and Search Console's data delay affect both numbers, so read a lost page as a reason to check its redirect and content, not as proof of what caused the loss.

## Limits

- Up to 10 plans per project, 20,000 old and 50,000 new addresses per plan.
- Only HTTPS addresses are tested. If the old site used `http://`, test that the HTTP version redirects to HTTPS with your host's tools.
- Results need Search Console for the property that holds both the old and the new addresses. After a domain move, add the new domain to Search Console and link it to the project, or compare the old and new properties in Search Console directly.
