# Local branches and reviews

Use **Local → Locations and review texts** for a business with several branches. Each additional branch has its own Google Maps identity, search area, language, keywords and check history.

## Add a branch

An editor supplies a label, exact numeric Google Maps **CID**, coordinates, language and keywords. SEOcc matches that CID rather than treating every branch on a shared website as the same business.

Run a point check manually or opt into weekly checks. Paid checks use your workspace's DataForSEO account and budget; the estimate is shown before you enable the schedule. Branch point checks cover one configured area.

## Compare a branch across its neighbourhood

Expand **Branch visibility grids** in the branch card. Select one of its keywords, a 3×3, 5×5 or 7×7 grid, and point spacing from 250 to 5,000 metres. Each grid is centred on that branch's saved coordinates. The screen shows estimated costs for each size; requesting a grid requires permission to run paid analyses and an available budget.

Cron checks one missing point at a time and saves progress before continuing. The heatmap matches the branch's exact CID, so another store sharing its website cannot count as this branch. A missing position means it was not found within the checked depth. Only one grid per branch runs at a time, and the latest 12 grids are shown. Weekly branch checks remain point checks; they do not automatically buy grids.

If a grid fails, inspect **System → Jobs** and usage before requesting another one. Completed points remain visible. Branch identity and search area remain fixed for consistent history.

## Keep the history consistent

You can edit a label or keywords and stop weekly checks without deleting history. A branch's CID, area and language are fixed for its history; create another branch for a different identity or search area. Removing a branch deletes its stored history.

## Import review texts

Request a review sample manually for a configured branch, or for the primary location once its CID has been discovered. The paid import requests up to **100 newest reviews**, including original text when available, author, rating, timestamp and owner reply.

The provider prepares the task asynchronously. SEOcc polls the saved task without another paid submission, for up to 24 hours. Weekly position checks do not fetch review texts automatically.

If a submission fails ambiguously, check **System → Jobs** and usage before manually requesting another import. SEOcc does not automatically repeat a potentially paid submission. A missing review in a sample does not prove it was deleted.

The reviews imported for the primary location feed **Review handling** on the Local page and the finding *Answer your Google reviews*. Import them again after a few weeks to see whether the reply share and the pace of new reviews have changed.
