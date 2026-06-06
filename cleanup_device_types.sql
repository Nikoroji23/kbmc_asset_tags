-- Fix Network Switch duplicates: keep ID 17, consolidate all others to it
UPDATE devices SET device_type_id = 17 WHERE device_type_id IN (31,33,35,37,40,43,50,58,60,76,78,80,83,85,87,90,96,98,100,102,105,108,110,119,121,123,125,127);
DELETE FROM device_types WHERE type_name = 'Network Switch' AND id != 17;

-- Fix Storage Device duplicates: keep ID 16, consolidate all others to it
UPDATE devices SET device_type_id = 16 WHERE device_type_id IN (18,19,20,21,22,23,24,25,26,27,28,29,30,32,34,36,38,39,41,42,44,45,46,47,48,49,51,52,53,54,55,56,57,59,61,62,63,64,65,66,67,68,69,70,71,72,73,74,75,77,79,81,82,84,86,88,89,91,92,93,94,95,97,99,101,103,104,106,107,109,111,112,113,114,115,116,117,118,120,122,124,126);
DELETE FROM device_types WHERE type_name = 'Storage Device' AND id != 16;

-- Verify cleanup
SELECT type_name, COUNT(*) as count FROM device_types WHERE type_name IN ('Storage Device', 'Network Switch') GROUP BY type_name;
