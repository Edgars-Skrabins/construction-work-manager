UPDATE a SET DESCRIPTION = d.DESCRIPTION
FROM ASSIGNMENT a
JOIN (VALUES
    (1, N'Pour the ground-floor slab of section B. Rebar inspection is booked for the morning of the first day; don''t start pouring before the inspector signs off.'),
    (25, N'Two coats on all reading-room walls and ceilings.' + NCHAR(10) + N'Use the low-VOC paint from the site container. The east wing stays open to the public, so keep the dust screens closed.'),
    (26, N'Excavate the pits for the parking ramp to -3.2 m. The survey team marks the levels each morning.'),
    (27, N'Lay the topsoil and lawn along the main entrance. The planting plan is on drawing L-02.'),
    (28, N'Timber frame for the new mezzanine in the main hall.'),
    (31, N'Water supply risers in staircases 1 and 2. Pressure test before the walls are closed and send the test report to the site manager.'),
    (32, N'Fit the remaining eight windows on the 3rd floor. The crane is available until 14:00 each day.'),
    (36, N'Interior plaster in classrooms 101–112, ready for painting.'),
    (3, N'Weld the new pipe supports in boiler room 2 during the planned shutdown.'),
    (35, N'Steel frame for the canopy over the ambulance entrance.' + NCHAR(10) + N'Hot work permit required every day. Report to the hospital technical service at the gate.'),
    (125, N'Formwork carpentry for the stair cores of building C.'),
    (127, N'Replace the drainage pipework in the school kitchen. Work only after 16:00 while school is in session.'),
    (133, N'Roof membrane on the new wing. Check the weather forecast; no work in rain or wind above 10 m/s.'),
    (134, N'Install the air handling units in server halls 1 and 2.' + NCHAR(10) + N'The data centre is live: escorted access only, and no dust-producing work without the client''s approval.'),
    (137, N'Foundations for the sports hall extension. Concrete deliveries arrive from 7:00.'),
    (145, N'Sprinkler pipework in the archive basement. Coordinate the fire-stopping with the electricians.'),
    (149, N'Ventilation ducts in the assembly hall ceiling. The scaffolding is already in place.'),
    (152, N'Facade panels on the turbine hall, north side first.'),
    (156, N'Interior plaster in apartments 1–24 of section A. Keep the stairwells clear for other trades.')
) AS d (ID, DESCRIPTION) ON d.ID = a.ID;
GO
