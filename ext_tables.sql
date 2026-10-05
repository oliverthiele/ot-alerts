#
# Table structure for table 'tx_otalerts_events'
#
# Columns are generated from TCA; only the unique key the upsert relies on
# needs to be declared here.
#
CREATE TABLE tx_otalerts_events
(
	UNIQUE KEY source_event (source, event_key)
);
