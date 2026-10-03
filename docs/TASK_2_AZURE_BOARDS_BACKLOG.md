# Task 2 implementation backlog

Use these items to create Azure Boards work items after the project is connected to GitHub. Assign real work item IDs, include the ID in branch names, and use `AB#<id>` in commit messages and pull request descriptions to link code activity to its work item. For example: branch `feature/41-login-carousel`, commit `AB#41 Add login image carousel`.

## Setup: connect Azure Boards and GitHub

**Work item title:** Connect the FSMS GitHub repository to Azure Boards

**Description:** Connect the existing GitHub repository `ST10345327/Tharimpepe_feeding_Scheme_Management_System` to the team's Azure Boards project so development activity can be associated with work items.

**Acceptance criteria:**

- The Azure Boards project is connected to the intended GitHub repository.
- A work item can be linked to a GitHub branch, commit, and pull request.
- A sample `AB#<id>` reference in a commit or PR description appears in the work item's Development links.
- Team members know which Azure Boards organization/project and GitHub repository to use.

**Setup permissions:** An Azure DevOps Project Collection Administrator (or project creator with the required rights) and a GitHub organization/repository owner or administrator may be needed. Avoid connecting the same repository to multiple Azure DevOps organizations.

## Product backlog

### Add a login-screen image carousel

**Work item title:** Add a responsive image carousel to the login screen

**Description:** Implement the login screen's rotating community images and captions for the website and Android app. Treat the image set as developer-managed static assets for this work item; there is no administrator upload or delete interface in this scope.Add a responsive image carousel to the login screen

**Acceptance criteria:**

- Carousel displays the approved local image assets and captions on the login screen.
- Images transition automatically and users can identify/navigate the available slides using visible indicators or controls.
- Layout and image crop work on desktop and mobile screen sizes.
- Carousel remains usable with keyboard/touch controls and respects reduced-motion preferences.
- Images used in the carousel have appropriate permission for project use and do not expose beneficiaries without consent.

**Out of scope / future proposal:** Allowing an administrator to upload, replace, reorder, or delete carousel images is a separate feature. Create and implement that work only after the full team reviews and approves it.

### Add help and user documentation

**Work item title:** Add user help for the website and Android app

**Description:** Finish and validate `docs/USER_GUIDE.md`, then provide a discoverable Help entry in both clients that opens the guide or an equivalent in-app help page.

**Acceptance criteria:**

- Guide describes sign-in, role/access behavior, beneficiaries, attendance, food stock, donations, volunteers, reports, and support.
- The website and Android app each expose a visible Help entry.
- Help content matches the shipped UI and is readable on mobile and desktop.
- Support instructions tell users how to report an issue without sharing passwords or unnecessary personal data.

### Add synthetic demo data

**Work item title:** Seed the MySQL database with dated synthetic demonstration records

**Description:** Add a repeatable, clearly labeled demo-data script for the MySQL schema. Use fictional names and dates, maintain valid foreign-key relationships, and provide at least 10 records for each table required by the assignment. Keep demo data separate from production data.

**Acceptance criteria:**

- Schema is created through MySQL/MariaDB using `sql/schema.sql` (the DBMS and schema are documented).
- Every required table has at least 10 records after the seed is applied; include a row-count query/report as evidence.
- Dates are coherent and varied; all people and organizations are fictional.
- Foreign-key relationships are valid and the script can be safely rerun or its reset behavior is documented.
- No real personal information or plaintext user passwords are included.

### Review and fix defects

**Work item title:** Review and fix defects in web and Android workflows

**Description:** Walk through the core user workflows on the website and Android app, record reproducible defects, and fix each confirmed issue in a separate linked work item or PR.

**Acceptance criteria:**

- Each defect includes steps to reproduce, expected behavior, actual behavior, and affected client.
- Fixes link to their work items with `AB#<id>` in commit or PR descriptions.
- Core workflows for sign-in, beneficiary management, attendance, stock, donations, and reports are reviewed on both clients where supported.
- Verification evidence is recorded for each fix.

### Verify database design and relationships

**Work item title:** Review and document the FSMS MySQL database design

**Description:** Verify that `sql/schema.sql` builds the intended FSMS tables and relationships in MySQL/MariaDB, and document the DBMS, entities, primary/foreign keys, and indexes for the submission.- Each defect includes steps to reproduce, expected behavior, actual behavior, and affected client.
- Fixes link to their work items with AB#<id> in commit or PR descriptions.
- Core workflows for sign-in, beneficiary management, attendance, stock, donations, and reports are reviewed on both clients where supported.
- Verification evidence is recorded for each fix.

**Acceptance criteria:**

- Schema applies successfully to a clean MySQL/MariaDB database.
- Table and relationship summary agrees with the implemented schema and ERD.
- Required record counts are demonstrated using synthetic seed data.
- Schema and seed instructions are documented for a teammate to reproduce.

## Suggested team workflow

1. Create the Azure Boards project and connect this GitHub repository.
2. Create the work items above and record their IDs in the team plan.
3. Create a branch per item, such as `feature/123-help-guide` or `fix/124-attendance-save`.
4. Include `AB#123` in commit messages and PR descriptions (the pull request title alone does not create the link).
5. Review the work item Development section after pushing/creating the PR and confirm the link appears.
6. Move each work item through the team's agreed states and attach implementation/verification evidence.

## Official setup references

- [Connect Azure Boards to GitHub](https://learn.microsoft.com/en-us/azure/devops/boards/github/connect-to-github?view=azure-devops)
- [Link GitHub commits, pull requests, and branches to work items](https://learn.microsoft.com/en-us/azure/devops/boards/github/link-to-from-github?view=azure-devops)
