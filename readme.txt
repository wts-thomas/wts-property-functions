== Changelog ==

1.3.0 – 11-18-2025
	•	Added Builder Selection dropdown within the Property post type.
	•	Automated Builder dropdown population, including alternate names (via ACF field cf_legalname_alternate_title).
	•	Implemented case-insensitive matching for Builder names (title or alternate title).
	•	Added Builder Auto-Fill on Save, pulling from MLS-fed builder field.
	•	Added Builder Sync Tool under Tools, allowing batch processing of 10 properties at a time.
	•	Ensured each property is processed only once via internal sync flags.
	•	Updated dropdown scripts so Estatik’s data-value is respected for both manual and batch updates.

1.2.0 – 11-14-2025
	•	Added Community Selection dropdown within the Property post type.
	•	Automated Community dropdown population, including alternate names.
	•	Implemented case-insensitive matching for subdivision names (from MLS) against Community titles.
	•	Added Community Auto-Fill on Save, mapping MLS subdivision to canonical Community name.
	•	Added Community Sync Tool under Tools, supporting batch updates of 10 properties at a time.
	•	Implemented internal flags so each property is processed once and skipped properties don’t block future batches.
	•	Updated dropdown scripts to load correct values by reading Estatik’s data-value attribute.

1.0.0 – 10-23-2025
	•	Initial launch.
	•	Core functions related to MLS-powered Property websites.