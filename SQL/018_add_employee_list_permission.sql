DECLARE @now INT = DATEDIFF(SECOND, '1970-01-01', SYSUTCDATETIME());

INSERT INTO RBAC_ITEM (name, type, description, created_at, updated_at) VALUES
    (N'employee.list', N'permission', N'List the employees in reach.', @now, @now);

INSERT INTO RBAC_ITEM_CHILD (parent, child) VALUES
    (N'admin', N'employee.list'),
    (N'manager', N'employee.list');
GO
