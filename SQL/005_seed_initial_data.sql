SET IDENTITY_INSERT EMPLOYEE ON;

INSERT INTO EMPLOYEE (ID, FIRST_NAME, LAST_NAME, BIRTH_DATE, ACCESS_LEVEL_ID, ROLE, MANAGER_ID) VALUES
    (1, N'Jānis', N'Bērziņš', '1980-03-14', 4, N'admin', NULL),
    (2, N'Ilze', N'Kalniņa', '1984-07-22', 4, N'manager', NULL),
    (3, N'Andris', N'Ozoliņš', '1979-11-05', 3, N'manager', NULL),
    (4, N'Mārtiņš', N'Liepiņš', '1990-05-18', 1, N'employee', 2),
    (5, N'Laura', N'Krūmiņa', '1993-09-30', 2, N'employee', 2),
    (6, N'Kristaps', N'Vītols', '1988-01-12', 3, N'employee', 2),
    (7, N'Anna', N'Zariņa', '1995-12-03', 4, N'employee', 3),
    (8, N'Roberts', N'Kalējs', '1987-04-27', 2, N'employee', 3);

SET IDENTITY_INSERT EMPLOYEE OFF;
GO

SET IDENTITY_INSERT CONSTRUCTION_SITE ON;

INSERT INTO CONSTRUCTION_SITE (ID, NAME, STREET, CITY, COUNTRY, AREA_M2, REQUIRED_ACCESS_LEVEL_ID) VALUES
    (1, N'Āgenskalns Residential Block', N'Mazā Nometņu iela 12', N'Rīga', N'Latvia', 4850.00, 1),
    (2, N'Daugavpils School Renovation', N'Saules iela 5', N'Daugavpils', N'Latvia', 3200.50, 2),
    (3, N'Rīga Thermal Power Plant Upgrade', N'Viskaļu iela 16', N'Rīga', N'Latvia', 12500.00, 3),
    (4, N'Ādaži Defence Facility', N'Kadagas iela 1', N'Ādaži', N'Latvia', 8700.75, 4);

SET IDENTITY_INSERT CONSTRUCTION_SITE OFF;
GO

SET IDENTITY_INSERT WORK_ITEM ON;

INSERT INTO WORK_ITEM (ID, NAME, DESCRIPTION) VALUES
    (1, N'Concrete works', N'Formwork, reinforcement and pouring of foundations, slabs and walls.'),
    (2, N'Electrical installation', N'Cabling, distribution boards and connection of lighting and power outlets.'),
    (3, N'Welding', N'Welding and assembly of steel structures and pipework.'),
    (4, N'Scaffolding assembly', N'Erecting, inspecting and dismantling scaffolding.'),
    (5, N'Roofing', N'Installation of roof membranes, insulation and flashing.');

SET IDENTITY_INSERT WORK_ITEM OFF;
GO
