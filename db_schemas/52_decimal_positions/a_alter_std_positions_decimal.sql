-- A step's position was a whole number, so a new step could only go where
-- the numbering had left a gap: ten MLAPHP steps sit at 10, 20 ... 100 and
-- an agent step wanted between 30 and 40 had nine places, at most. Positions
-- now take up to four decimal places (39.0001 comes after 39 and before 40),
-- so nearly ten thousand steps fit between two whole numbers and no file is
-- renumbered. Tracks share the rule and the type.
ALTER TABLE std_tracks
    MODIFY position DECIMAL(9,4) NOT NULL;
ALTER TABLE std_steps
    MODIFY position DECIMAL(9,4) NOT NULL;
