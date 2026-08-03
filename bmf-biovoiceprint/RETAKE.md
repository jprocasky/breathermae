# BioVoicePrint retake (v0.2.9-poc)

Chip-based step retake while group is in_progress.

- `POST /wp-json/bmf-biovoice/v1/groups/{id}/retake`
- Body: `{ "task_code": "phonation_1", "clear_forward": false }`
- Hard-deletes session row + audio file
- Completed/final groups are locked
- Honour protocol `allow_retake` flag
- Wizard: done chips open confirm (this step only vs this step + forward)

Source files updated in local artifacts; full PHP/JS/CSS push follows.
