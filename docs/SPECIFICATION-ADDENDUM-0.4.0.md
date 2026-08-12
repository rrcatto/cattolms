# Specification addendum — version 0.4.0

- Main user navigation is Dashboard, Catalogue, My Learning and Profile.
- Platform administration is one tabbed control centre.
- Every user has a common profile containing personal particulars, email addresses, company, roles, learning summary and active sessions.
- The platform has one permanent, renameable System Company for users not belonging to an external company.
- A course has an owner, zero or more Course Editors and learners.
- Platform administrators may correct enrolments, progress and credits through audited controls.
- Company administrators may remove a started course from their learner; the consumed credit is not returned.
- The Default theme is a filesystem recovery theme. New themes are stored in PostgreSQL and controlled through Theme Studio.
- Three palettes are supplied: Rose & Ice, Citrus, and Ocean & Sun.
- The global layout must initialise optional script flags to safe defaults and errors must never render recursively into an existing page.