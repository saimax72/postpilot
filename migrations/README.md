# Migrations

Run these in phpMyAdmin (SQL tab) in numerical order, once each, against your
PostPilot database. A fresh install created from `schema.sql` already contains
everything here and needs none of them.

| File | Adds | Needed by |
|---|---|---|
| `001_media_crop.sql` | 5 columns on `posts` | image cropping, alt text, first comment, bulk upload |
| `002_hashtag_sets.sql` | `hashtag_sets` table | saved hashtag sets |
| `003_post_templates.sql` | `post_templates` table | post templates, bulk upload |

`002` and `003` use `CREATE TABLE IF NOT EXISTS`, so re-running them is safe.

`001` uses `ALTER TABLE ADD COLUMN`, which is **not** safe to re-run - it fails
with "Duplicate column name" if the columns already exist. That failure is
harmless; it just means the migration already ran.

## Symptoms of a missed migration

- `Unknown column 'media_original'` when saving a post or running bulk upload -> run `001`
- Hashtag sets page always empty, no error -> run `002`
- Templates page always empty, no error -> run `003`

Missing tables for `002` and `003` are caught and degrade to an empty list, so
the rest of the app keeps working. A missing column from `001` is not catchable
in the same way and will surface as the error above.
