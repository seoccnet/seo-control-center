# 9. Users, workspaces and security

## Workspaces

A **workspace** holds projects, integrations (Google, DataForSEO), AI tokens and members. Agencies typically use one workspace per client team; everyone else needs only one. If you belong to several workspaces, switch between them at the top of the sidebar.


### Creating a workspace

The installation administrator creates workspaces: click **New workspace** below the workspace name at the top of the sidebar, enter a name and click **Create workspace**. You become its owner and switch to it right away; add members under *Workspace → Members*. If your licence allows no further workspace, the page says so.

### Renaming and deleting

*Workspace → Settings*:

- **Owners and admins** can rename the workspace.
- **Owners** can delete it. Type the workspace name to confirm. Members lose access immediately, and all projects of the workspace are deleted with it, including their scheduled imports and rank checks. The data stays in the database but is no longer shown anywhere.

A workspace cannot be deleted if it is the installation's only workspace, or if you have no other workspace to continue in.

## Roles

| Role | Can |
|---|---|
| **Owner** | Everything in the workspace, including deleting it |
| **Admin** | Everything except deleting the workspace: projects, integrations, members, AI tokens, audit log |
| **Member** | View and edit projects, start paid analyses (metrics, rank checks) and site audits |
| **Viewer** | View projects only |

Projects can additionally be limited to certain members. A foreign project is simply not found.

The **installation administrator** (created by the installer) also manages the installation itself under *System*: health, jobs, backups, updates and the Google app.

## Team members

*Workspace → Members* lists who has access to the workspace and with which role. Owners and admins also manage members there.

### Adding a member

Under **Add a member**, enter the person's e-mail address and choose a role (Admin, Member or Viewer).

- **The address already has an account** (for example a colleague in another workspace): the person is added right away.
- **New person:** you get an **invitation link**. Copy it and send it to them yourself, for example by e-mail or chat, because SEO Control Center does not send e-mails. On the link's page, they choose their name and password and can then sign in.

The link is shown **only once**, works once and expires after **7 days**. Open invitations are listed under *Open invitations*. If a link is lost or went to the wrong person, **revoke** it and add the person again.

### Changing roles and removing members

- Change a member's role in the table and click **Save**.
- **Remove** takes away the person's access to this workspace immediately. Their account remains, along with any other workspaces they belong to.

Some limits keep a workspace safe:

- the **Owner** role cannot be given here;
- only an owner can change or remove another owner;
- nobody can change their own role or remove themselves.

Every change is recorded in the **Audit log**.

## Your account

Click your name at the bottom of the sidebar to open **Your account**:

- **Name:** change it at any time.
- **E-mail address:** this is the address you sign in with. Changing it asks for your current password.
- **Password:** enter your current password and the new one twice. All your other sessions, for example on another computer, are signed out; the current one stays signed in.

After five wrong current passwords, these changes are locked for 15 minutes.

## Managing users (installation administrator)

**System → Users** lists every account of the installation with its workspaces, two-factor status and last sign-in. For each user you can:

| Action | Effect |
|---|---|
| **Deactivate** | The user can no longer sign in. Their sessions end immediately and their AI access tokens stop working. Nothing is deleted. |
| **Reactivate** | Undoes a deactivation. |
| **Reset two-factor** | For a lost phone: the user signs in with their password alone and can set up two-factor sign-in again. |
| **Send reset link** | E-mails the user a link to choose a new password (needs *System → E-mail*). |
| **Make admin / Remove admin** | Gives or takes the rights to manage the installation (*System*). |

You cannot deactivate yourself or remove your own administrator rights, and the last active administrator always stays. Every action is recorded in the audit log.

Workspace roles (owner, admin, member, viewer) are managed per workspace under *Workspace → Members*.

## White label (installation administrator)

**System → Branding** shows SEO Control Center under your own brand: in the app, on the sign-in page, on report pages and in report e-mails.

- **Product name:** replaces "SEO Control Center" in the sidebar, the browser tab and reports.
- **Accent colour:** a hex colour such as `#0f7b6c`. It is used for buttons, links and highlights in light and dark mode, and for the button in report e-mails.
- **Report footer:** replaces "Sent by SEO Control Center" at the end of reports, for example "Prepared by Your Agency · hello@your-agency.example".
- **Logo:** PNG, JPEG or WebP, up to 512 KB, shown 30 pixels high. Report e-mails show the name only, because e-mail programs do not load images.

The sender name of e-mails is set under *System → E-mail*. Empty fields keep the defaults.

## Forgot your password?

On the sign-in page, click **Forgot your password?** and enter your e-mail address. You receive a link to choose a new password; it works once and for 60 minutes. Afterwards all your sessions are signed out and you sign in with the new password.

This needs e-mail to be set up under *System → E-mail* ([chapter 2, step 7](02-installation.md)). Without it, the page tells you to ask your administrator.

When e-mail is set up, **invitations** are sent by e-mail too, and members who already had an account get a short note that they were added.

## Signing in securely

Under **Account security** (also linked from *Your account*):

- **Two-factor sign-in:** scan the QR code with an authenticator app and confirm with a code. Store the **recovery codes** somewhere safe; each one works once if you lose your phone.
- **Sessions:** *Sign out other sessions* ends every session except the current one, for example after using a shared computer.

Further protection:

- repeated wrong passwords are slowed down and limited;
- sessions end after a period of inactivity;
- every sensitive action is recorded in the **Audit log**.

## Your data

- All SEO data stays in **your own database** on your server.
- Passwords are stored only as secure hashes. Google and DataForSEO credentials, and two-factor secrets, are stored **encrypted**.
- SEO Control Center contacts only these services:
  - **Google**, for Search Console;
  - **DataForSEO**, if connected;
