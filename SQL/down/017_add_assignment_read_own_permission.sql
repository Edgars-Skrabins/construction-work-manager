DELETE FROM RBAC_ITEM_CHILD WHERE child = N'assignment.readOwn';
GO

DELETE FROM RBAC_ITEM WHERE name = N'assignment.readOwn';
GO
