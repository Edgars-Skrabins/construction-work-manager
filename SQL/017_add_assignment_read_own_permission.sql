DECLARE @now INT = DATEDIFF(SECOND, '1970-01-01', SYSUTCDATETIME());

INSERT INTO RBAC_ITEM (name, type, description, created_at, updated_at) VALUES
    (N'assignment.readOwn', N'permission', N'Read own work assignments.', @now, @now);

INSERT INTO RBAC_ITEM_CHILD (parent, child) VALUES
    (N'employee', N'assignment.readOwn');
GO
