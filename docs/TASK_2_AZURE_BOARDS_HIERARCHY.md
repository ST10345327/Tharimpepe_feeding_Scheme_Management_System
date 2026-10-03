# Task 2 Azure Boards hierarchy

This is a proposed parent/child structure for the Task 2 work items shown in Azure Boards. Reuse the existing items and create story-level parents; avoid importing duplicate tasks.

## Existing work items to keep

| ID | Current title | Proposed placement |
|---:|---|---|
| 33 | Project Planning | Rename to the Task 2 Epic below. |
| 34 | Set up initial FSMS Task 2 DevOps tracking | Child task of Story 1. |
| 35 | Development | Child task of Story 2; split into focused tasks if needed. |
| 36 | Database | Child task of Story 3; add a separate seed-data task. |
| 37 | Testing | Child task of Story 4; add focused test tasks. |
| 38 | Documentation | Child task of Story 5; add screenshot/help tasks. |
| 39 | Prototype | Child task of Story 2. |
| 40 | Connect FSMS GitHub repository to Azure DevOps | Child task of Story 1; keep assigned to the admin. |

## Hierarchy

### Epic #33 — Task 2: FSMS Implementation, Testing, and DevOps Tracking

**Description:** Deliver and demonstrate the Task 2 FSMS solution: working prototype and Android app, MySQL database and synthetic records, tested workflows, user help, and traceable team work in Azure Boards and GitHub.

**Epic completion criteria:**

- The prototype is ready for review and feedback.
- Android app and supported website workflows are implemented and verified.
- MySQL schema, relationships, and required synthetic records are documented and demonstrated.
- Users can find guidance for using the system.
- Team work and code changes are traceable through Azure Boards and GitHub.

#### Feature 1 — Task 2 planning and DevOps traceability

##### User Story 1 — Track Task 2 work and GitHub development activity

**User story:** As a project team member, I want Task 2 work items connected to GitHub development activity so that we can track ownership and progress from planning through code review.

**Acceptance criteria:**

- Team backlog contains the Task 2 work and has owners and agreed states.
- Admin connects the intended GitHub repository to the Azure Boards project.
- A branch, commit, and pull request can be linked to a work item.
- Team uses the work item ID in branch naming and `AB#<id>` in commit messages and PR descriptions after the connection works.

**Existing child tasks:** #34, #40.

#### Feature 2 — Working FSMS prototype

##### User Story 2 — Deliver a working mobile prototype

**User story:** As a project reviewer, I want a working Android prototype of the feeding scheme system so that I can review its main screens and workflows and provide feedback.

**Acceptance criteria:**

- Android app builds and opens to the login screen.
- Main supported workflows can be demonstrated using the agreed demo account/data.
- Prototype is available for review and feedback is captured.
- Login carousel works on the website and Android screen with local bundled assets, buttons/dots, and touch swipe.
- Carousel uses static bundled assets only. Admin upload/delete is not included unless the whole team approves that separate feature.

**Existing child tasks:** #35, #39.

**Suggested child task under #35:** Implement and verify the static login carousel in web and Android clients.

#### Feature 3 — MySQL database and demonstration data

##### User Story 3 — Store FSMS data in a designed MySQL database

**User story:** As a scheme administrator, I want FSMS records stored in a relational MySQL database so that beneficiaries, attendance, stock, donations, and volunteers can be managed consistently.

**Acceptance criteria:**

- Database is designed and developed using MySQL/MariaDB DBMS.
- Tables, primary keys, foreign keys, constraints, and indexes are documented and agree with `sql/schema.sql`.
- Each table required for the assignment contains at least ten fictional records with coherent dates.
- Seed instructions and table row counts are included as evidence.

**Existing child task:** #36.

**Suggested child tasks:** Create the synthetic dated seed script; apply the schema and seed to a clean DBMS database; record row counts and relationship evidence.

#### Feature 4 — Quality and user support

##### User Story 4 — Verify the system workflows

**User story:** As a project team, we want the website and Android workflows checked so that confirmed defects can be fixed before prototype review.

**Acceptance criteria:**

- Agreed core workflows are checked on the website and Android where supported.
- Each confirmed bug records reproduction steps, expected result, actual result, and affected client.
- Fixes are linked to their work items and evidence is attached or recorded.

**Existing child task:** #37.

**Suggested child tasks:** Check sign-in and role access; check beneficiary and attendance flows; check stock and donations; check reports; record and resolve confirmed defects.

##### User Story 5 — Provide user help and documentation

**User story:** As a staff member or volunteer, I want clear help for the website and Android app so that I can complete common tasks and know how to get support.

**Acceptance criteria:**

- User guide covers sign-in, role access, beneficiaries, attendance, food stock, donations, volunteers, and reports.
- Guide includes clear screenshots from the current app and captions for important actions.
- Screenshots contain no real passwords, tokens, or unnecessary personal information.
- Help entry is discoverable in both clients, or the agreed documentation-only submission scope is recorded.

**Existing child task:** #38.

**Suggested child tasks:** Validate `docs/USER_GUIDE.md` against current clients; capture and caption website screenshots; capture and caption Android screenshots; add or link a Help entry in both clients.

## Reparenting checklist

1. Rename Epic #33 as shown above.
2. In an Agile or Scrum project, create the four Features under Epic #33, then create the five User Stories/Product Backlog Items under the matching Feature. In a Basic project, create the equivalent Issues directly under the Epic.
3. Reparent existing Tasks #34–#40 under the matching story/backlog item (or Basic Issue) in the table.
4. Add the suggested focused child tasks only where the existing task does not already cover that work.
5. Keep #40 assigned to the admin because that person owns the repository connection.
6. Do not mark stories/tasks done until their acceptance criteria and evidence are satisfied.

## Process template note

Agile uses **Epic → Feature → User Story → Task**. Scrum uses **Epic → Feature → Product Backlog Item → Task**. Basic uses **Epic → Issue → Task**. Confirm the project's process before creating the parent items; use the equivalent story-level type available in that process.

Official references: [Plan and track work in Azure Boards](https://learn.microsoft.com/en-us/azure/devops/boards/get-started/plan-track-work?view=azure-devops), [define features and epics](https://learn.microsoft.com/en-us/azure/devops/boards/backlogs/define-features-epics?view=azure-devops).
