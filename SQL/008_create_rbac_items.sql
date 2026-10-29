CREATE TABLE RBAC_ITEM (
    name NVARCHAR(126) NOT NULL,
    type NVARCHAR(10) NOT NULL,
    description NVARCHAR(191) NULL,
    rule_name NVARCHAR(64) NULL,
    created_at INT NOT NULL,
    updated_at INT NOT NULL,
    CONSTRAINT PK_RBAC_ITEM PRIMARY KEY (name),
    CONSTRAINT UQ_RBAC_ITEM_NAME_TYPE UNIQUE (name, type),
    CONSTRAINT CK_RBAC_ITEM_TYPE CHECK (type IN (N'role', N'permission'))
);
GO

CREATE INDEX IX_RBAC_ITEM_TYPE ON RBAC_ITEM (type);
GO

CREATE TABLE RBAC_ITEM_CHILD (
    parent NVARCHAR(126) NOT NULL,
    child NVARCHAR(126) NOT NULL,
    CONSTRAINT PK_RBAC_ITEM_CHILD PRIMARY KEY (parent, child),
    CONSTRAINT FK_RBAC_ITEM_CHILD_PARENT FOREIGN KEY (parent) REFERENCES RBAC_ITEM (name),
    CONSTRAINT FK_RBAC_ITEM_CHILD_CHILD FOREIGN KEY (child) REFERENCES RBAC_ITEM (name)
);
GO

DECLARE @now INT = DATEDIFF(SECOND, '1970-01-01', SYSUTCDATETIME());

INSERT INTO RBAC_ITEM (name, type, description, created_at, updated_at) VALUES
    (N'admin', N'role', N'Manages employees, construction sites, work items and all assignments.', @now, @now),
    (N'manager', N'role', N'Assigns work to their subordinates.', @now, @now),
    (N'employee', N'role', N'Sees their own assigned work.', @now, @now),
    (N'employee.create', N'permission', N'Create employees.', @now, @now),
    (N'employee.read', N'permission', N'Read employees.', @now, @now),
    (N'employee.update', N'permission', N'Update employees.', @now, @now),
    (N'employee.delete', N'permission', N'Delete employees.', @now, @now),
    (N'site.create', N'permission', N'Create construction sites.', @now, @now),
    (N'site.read', N'permission', N'Read construction sites.', @now, @now),
    (N'site.update', N'permission', N'Update construction sites.', @now, @now),
    (N'site.delete', N'permission', N'Delete construction sites.', @now, @now),
    (N'workItem.create', N'permission', N'Create work items.', @now, @now),
    (N'workItem.read', N'permission', N'Read work items.', @now, @now),
    (N'workItem.update', N'permission', N'Update work items.', @now, @now),
    (N'workItem.delete', N'permission', N'Delete work items.', @now, @now),
    (N'assignment.create', N'permission', N'Create work assignments.', @now, @now),
    (N'assignment.read', N'permission', N'Read work assignments.', @now, @now),
    (N'assignment.update', N'permission', N'Update work assignments.', @now, @now),
    (N'assignment.delete', N'permission', N'Delete work assignments.', @now, @now);
GO

INSERT INTO RBAC_ITEM_CHILD (parent, child)
SELECT N'admin', name FROM RBAC_ITEM WHERE type = N'permission';

INSERT INTO RBAC_ITEM_CHILD (parent, child) VALUES
    (N'manager', N'employee.read'),
    (N'manager', N'site.read'),
    (N'manager', N'workItem.read'),
    (N'manager', N'assignment.create'),
    (N'manager', N'assignment.read'),
    (N'manager', N'assignment.update'),
    (N'manager', N'assignment.delete'),
    (N'employee', N'employee.read'),
    (N'employee', N'site.read'),
    (N'employee', N'workItem.read'),
    (N'employee', N'assignment.read');
GO
