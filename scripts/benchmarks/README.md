# SQL benchmark

The catalog contains 20 parameterized read-only SELECT patterns from the repositories under `src/repository`. Each definition records its source method, fixed synthetic parameter case, tables, and candidate indexes. Candidate indexes are hypotheses; the captured MariaDB plans will determine actual index selection.

Queries using primary, unique, or foreign-key indexes act as controls. FULLTEXT queries are excluded because removing their required index would make MATCH invalid in the baseline. The workload includes catalog filtering, variants, order/cart lookups, chat history, support tickets, FAQ search, and joins/aggregates.

Use only disposable synthetic MariaDB databases. Never run the fixture or variant builder against the application database.
