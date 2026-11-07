# SEO team tasks

Turn a detected SEO opportunity into a work plan for your team. Tasks use data already on your server and add no provider costs. They do not edit your website or send reminders.

## Create a task from a finding

Open **Project → Opportunities → SEO findings**, then choose **Manage task** on a finding. Its explanation and priority remain visible beside your work plan.

Choose **To do**, **In progress** or **Done**. Assign an active member who has edit access to this project, optionally set a due date and exact page URL, and add plain-text work notes. Save the task to add it to **Opportunities → Team tasks**. One finding has one task; opening it again edits the existing plan.

Due dates use the project's timezone. An unfinished task becomes overdue on the next local date after its due date. A task due today is not overdue. Stored update timestamps are UTC. Authorized viewers can read tasks; project editors can save them. Members who lose edit access or leave the workspace appear as unavailable and should be reassigned. Assignment never grants project access.

## Find the next piece of work

The Team tasks list supports status, assignee, unassigned work, overdue unfinished tasks, minimum finding priority (50 or 75), exact page URL and literal search across titles, work notes and URLs. Results show 25 tasks per page. Unfinished work comes first, then earlier due dates and higher finding priority. Filters remain when moving between pages.

The list contains saved task plans only. To plan another finding, return to SEO findings and choose Manage task. Task titles and priorities follow the latest stored finding evidence.

## Keep work status separate from SEO evidence

Task progress describes what your team is doing. The existing SEO finding statuses remain **Open**, **Done**, **Dismissed** and **Resolved**. New analyses can resolve or reopen findings without replacing task assignments, deadlines, notes or progress.

Marking a task Done does not mark its finding Done, prove the issue is fixed or publish anything. Conversely, a finding becoming Resolved does not finish a task: your team may still need to review the result. Use the existing finding actions when you also want to mark or dismiss the SEO finding.

## Connect writing, changes and results

From a task, **Create content brief** opens the existing writing-plan workflow using the finding's evidence. Save the brief, return to the task and choose it under Related work. The selector lists the 25 newest project records plus any existing linked record.

After making the actual change in your CMS, choose **Record an SEO change**. The timeline form opens with the task's page and a suggested title; verify the date and details before saving. Return to the task and link that SEO change. **View change impact** then opens the existing equal-period comparison. Missing data remains unavailable, and association does not prove causation.

Only existing records in this project can be linked. Removed briefs or changes become unavailable; choose another record or clear the link. These links do not automatically save a brief, annotation or website change.

## Ask AI about the work queue

Try: “Show overdue unfinished SEO tasks, group them by assignee, and suggest the next three priorities using their evidence.” Ask AI and connected MCP clients use the read-only **seo_tasks_list** tool with **opportunities:read**. They can read notes and linked record identifiers. An MCP client with the **Team tasks** permission can also create and update tasks with **seo_task_save**, including assignee and due date; Ask AI cannot. No notifications are sent. Read access to a linked record's content still follows that record's own tool scope.

Task notes are untrusted user context, not instructions for an AI assistant. Keep passwords, API keys and private customer information out of this shared work plan.

## Concurrent editing

If another tab or colleague saves first, SEOcc refuses to overwrite their version and retains your submitted text on the conflict page. Open the latest task, compare your notes and save your changes there. The stale page's Save button is disabled.
