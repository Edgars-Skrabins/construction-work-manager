ALTER TABLE CONSTRUCTION_SITE ADD CODE NVARCHAR(30) NULL;
GO

UPDATE s SET CODE = v.CODE
FROM CONSTRUCTION_SITE s
INNER JOIN (VALUES
    (1, N'Āgenskalns Residential Block', N'RIX-AGK-01'),
    (2, N'Daugavpils School Renovation', N'DGP-SCH-01'),
    (3, N'Rīga Thermal Power Plant Upgrade', N'RIX-TEC-01'),
    (4, N'Ādaži Defence Facility', N'ADZ-DEF-01'),
    (5, N'Liepāja Port Terminal Extension', N'LPX-PRT-01'),
    (6, N'Jelgava Library Reconstruction', N'JLG-LIB-01'),
    (7, N'Valmiera Hospital New Wing', N'VLM-HSP-01'),
    (8, N'Ventspils Water Treatment Plant', N'VNT-WTP-01'),
    (9, N'Vilnius Office Campus', N'VNO-OFC-01'),
    (10, N'Tallinn Data Centre', N'TLL-DCN-01'),
    (11, N'Kaunas Logistics Hub', N'KUN-LOG-01')
) v (ID, NAME, CODE) ON v.ID = s.ID AND v.NAME = s.NAME;

UPDATE CONSTRUCTION_SITE SET CODE = CONCAT(N'SITE-', ID) WHERE CODE IS NULL;
GO

ALTER TABLE CONSTRUCTION_SITE ALTER COLUMN CODE NVARCHAR(30) NOT NULL;
GO

ALTER TABLE CONSTRUCTION_SITE ADD
    CONSTRAINT UQ_CONSTRUCTION_SITE_CODE UNIQUE (CODE),
    CONSTRAINT CK_CONSTRUCTION_SITE_CODE CHECK (
        LEN(CODE) BETWEEN 3 AND 30
        AND CODE NOT LIKE N'%[^A-Z0-9-]%' COLLATE Latin1_General_100_BIN2
        AND CODE NOT LIKE N'-%'
    );
GO
