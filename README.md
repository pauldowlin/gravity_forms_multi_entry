# Gravity Forms Multi Entry
Convert a single entry into multiple entries.

## Who Is This For
This Gravity Forms Addon is meant for Sports Associations, Race Organizers (be that running, biking etc) and anyone that takes online registrations where there may be families or groups with multiple registrants. 

## How Does It Work
In the Gravity Forms editor, create a section and check the Multi Entry setting under the General Tab (you must enable Multi Entry under Form Settings in order to see this). Put your registrant info under this section. Repeat with section field for however many registrants you want to account for in the form. Place a section field that is not Multi Entry enabled after all registrant info and continue your form as normal.

## Under Development
Right now the addon will take form data and place it into a separate database with individual registrants split correctly. You may look in the table _me_registration_multi_entry to verify that this is the case.

## To Do
1. Create Export function so that users can download Multi Entry entries
2. Create Gravity Forms tab so users can view Multi Entry entries
3. Create Uninstall function so we can cleanly remove database
