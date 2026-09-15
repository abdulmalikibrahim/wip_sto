-- Part List upload gets a third mode, 'upsert' (add new rows + update existing
-- ones, the new default). 'append' now means add new rows only, leaving rows
-- already present untouched. Until this runs, upsert uploads are logged as
-- 'append' (see Part_list_model::log_upload()).

ALTER TABLE `part_list_upload_log`
  MODIFY `mode` enum('append','upsert','replace') NOT NULL DEFAULT 'append';
