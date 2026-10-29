DECLARE @today DATE = CAST(SYSDATETIMEOFFSET() AT TIME ZONE 'FLE Standard Time' AS DATE);

SET IDENTITY_INSERT ASSIGNMENT ON;

INSERT INTO ASSIGNMENT (ID, EMPLOYEE_ID, WORK_ITEM_ID, CONSTRUCTION_SITE_ID, START_DATE, END_DATE, STATUS, CREATED_BY_ID) VALUES
    (1, 4, 1, 1, DATEADD(DAY, -7, @today), DATEADD(DAY, 14, @today), N'active', 2),
    (2, 5, 2, 2, DATEADD(DAY, 7, @today), DATEADD(DAY, 30, @today), N'active', 2),
    (3, 6, 3, 3, DATEADD(DAY, -30, @today), DATEADD(DAY, -3, @today), N'active', 2),
    (4, 7, 4, 4, DATEADD(DAY, -2, @today), DATEADD(DAY, 20, @today), N'active', 3),
    (5, 8, 5, 1, DATEADD(DAY, -40, @today), DATEADD(DAY, -10, @today), N'done', 3),
    (6, 8, 4, 2, DATEADD(DAY, 3, @today), DATEADD(DAY, 10, @today), N'cancelled', 3);

SET IDENTITY_INSERT ASSIGNMENT OFF;
GO
