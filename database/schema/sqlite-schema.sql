CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "webauthn_credentials"(
  "id" varchar not null,
  "authenticatable_type" varchar not null,
  "authenticatable_id" integer not null,
  "user_id" varchar not null,
  "alias" varchar,
  "counter" integer,
  "rp_id" varchar not null,
  "origin" varchar not null,
  "transports" text,
  "aaguid" varchar,
  "public_key" text not null,
  "attestation_format" varchar not null default 'none',
  "certificates" text,
  "disabled_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "webauthn_user_index" on "webauthn_credentials"(
  "authenticatable_type",
  "authenticatable_id"
);
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "nickname" varchar not null,
  "email" varchar,
  "internet_blocked" tinyint(1) not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  "external_id" varchar,
  "access_token" text,
  "refresh_token" text,
  "token_expires_at" datetime,
  "avatar_url" varchar,
  "password" varchar,
  "internet_enabled" tinyint(1) not null default '0',
  "rate_limit_enabled" tinyint(1) not null default '0',
  "dns_filtering_enabled" tinyint(1) not null default '0',
  "weekly_bandwidth" integer not null default '0',
  "weekly_received" integer not null default '0',
  "weekly_sent" integer not null default '0'
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "settings"(
  "id" integer primary key autoincrement not null,
  "code" varchar not null,
  "name" varchar not null,
  "description" varchar,
  "type" varchar not null default 'String',
  "value" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "settings_code_unique" on "settings"("code");
CREATE TABLE IF NOT EXISTS "roles"(
  "id" integer primary key autoincrement not null,
  "code" varchar not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "roles_code_unique" on "roles"("code");
CREATE TABLE IF NOT EXISTS "role_user"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "role_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("role_id") references "roles"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "user_ip_addresses"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "ip_address_id" integer not null,
  "last_seen_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("ip_address_id") references "ip_addresses"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "content_blocks"(
  "id" integer primary key autoincrement not null,
  "type" varchar not null,
  "title" varchar not null,
  "content" text,
  "is_active" tinyint(1) not null default '1',
  "settings" text,
  "created_at" datetime,
  "updated_at" datetime,
  "grid_col" integer not null default '1',
  "grid_row" integer not null default '1',
  "col_span" integer not null default '1',
  "row_span" integer not null default '1'
);
CREATE TABLE IF NOT EXISTS "mac_addresses"(
  "id" integer primary key autoincrement not null,
  "mac_address" varchar not null,
  "user_id" integer,
  "source" varchar not null,
  "description" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "mac_addresses_mac_address_unique" on "mac_addresses"(
  "mac_address"
);
CREATE UNIQUE INDEX "users_external_id_unique" on "users"("external_id");
CREATE TABLE IF NOT EXISTS "integration_configs"(
  "id" integer primary key autoincrement not null,
  "integration" varchar not null,
  "key" varchar not null,
  "value" text,
  "encrypted" tinyint(1) not null default '0',
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "integration_configs_integration_key_unique" on "integration_configs"(
  "integration",
  "key"
);
CREATE INDEX "integration_configs_integration_index" on "integration_configs"(
  "integration"
);
CREATE TABLE IF NOT EXISTS "switch_configs"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "hostname" varchar not null,
  "type" varchar not null default 'cisco',
  "username" varchar not null,
  "password" text not null,
  "enable_password" text,
  "enabled" tinyint(1) not null default '1',
  "port" integer not null default '22',
  "timeout" integer not null default '5',
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "switch_configs_hostname_unique" on "switch_configs"(
  "hostname"
);
CREATE TABLE IF NOT EXISTS "capability_assignments"(
  "id" integer primary key autoincrement not null,
  "capability" varchar not null,
  "integration" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "capability_assignments_capability_unique" on "capability_assignments"(
  "capability"
);
CREATE INDEX "capability_assignments_integration_index" on "capability_assignments"(
  "integration"
);
CREATE TABLE IF NOT EXISTS "connection_test_logs"(
  "id" integer primary key autoincrement not null,
  "integration" varchar not null,
  "success" tinyint(1) not null,
  "message" text,
  "response_time_ms" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "response_data" text,
  "request_method" varchar,
  "request_url" text,
  "response_status" integer
);
CREATE INDEX "connection_test_logs_integration_index" on "connection_test_logs"(
  "integration"
);
CREATE TABLE IF NOT EXISTS "switch_ports"(
  "id" integer primary key autoincrement not null,
  "switch_config_id" integer not null,
  "port_name" varchar not null,
  "port_number" varchar not null,
  "switch_description" varchar,
  "admin_notes" varchar,
  "access_vlan" integer,
  "switchport_mode" varchar,
  "speed" varchar,
  "status" varchar not null default 'down',
  "admin_status" varchar not null default 'up',
  "duplex" varchar,
  "poe_status" varchar,
  "last_synced_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("switch_config_id") references "switch_configs"("id") on delete cascade
);
CREATE UNIQUE INDEX "switch_ports_switch_config_id_port_name_unique" on "switch_ports"(
  "switch_config_id",
  "port_name"
);
CREATE INDEX "switch_ports_status_index" on "switch_ports"("status");
CREATE TABLE IF NOT EXISTS "switch_sync_runs"(
  "id" integer primary key autoincrement not null,
  "switch_config_id" integer not null,
  "status" varchar not null default 'pending',
  "started_at" datetime,
  "finished_at" datetime,
  "error" text,
  "ports_created" integer not null default '0',
  "ports_updated" integer not null default '0',
  "macs_created" integer not null default '0',
  "macs_updated" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("switch_config_id") references "switch_configs"("id") on delete cascade
);
CREATE INDEX "switch_sync_runs_switch_config_id_status_index" on "switch_sync_runs"(
  "switch_config_id",
  "status"
);
CREATE TABLE IF NOT EXISTS "switch_port_configs"(
  "id" integer primary key autoincrement not null,
  "switch_port_id" integer not null,
  "config_text" text not null,
  "config_hash" varchar not null,
  "last_fetched_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "interface_output" text,
  foreign key("switch_port_id") references "switch_ports"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "user_parameters"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "key" varchar not null,
  "value" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "user_parameters_user_id_key_unique" on "user_parameters"(
  "user_id",
  "key"
);
CREATE TABLE IF NOT EXISTS "pages"(
  "id" integer primary key autoincrement not null,
  "title" varchar not null,
  "slug" varchar not null,
  "content" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "pages_slug_unique" on "pages"("slug");
CREATE TABLE IF NOT EXISTS "ip_address_mac_address"(
  "id" integer primary key autoincrement not null,
  "ip_address_id" integer not null,
  "mac_address_id" integer not null,
  "source" varchar not null,
  "last_seen_at" datetime not null default CURRENT_TIMESTAMP,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("ip_address_id") references "ip_addresses"("id") on delete cascade,
  foreign key("mac_address_id") references "mac_addresses"("id") on delete cascade
);
CREATE UNIQUE INDEX "ip_address_mac_address_ip_address_id_mac_address_id_unique" on "ip_address_mac_address"(
  "ip_address_id",
  "mac_address_id"
);
CREATE TABLE IF NOT EXISTS "switch_port_macs"(
  "id" integer primary key autoincrement not null,
  "switch_port_id" integer not null,
  "mac_address" varchar not null,
  "vlan" integer,
  "last_seen_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "mac_address_id" integer,
  foreign key("switch_port_id") references switch_ports("id") on delete cascade on update no action,
  foreign key("mac_address_id") references "mac_addresses"("id") on delete set null
);
CREATE INDEX "switch_port_macs_mac_address_index" on "switch_port_macs"(
  "mac_address"
);
CREATE UNIQUE INDEX "switch_port_macs_switch_port_id_mac_address_vlan_unique" on "switch_port_macs"(
  "switch_port_id",
  "mac_address",
  "vlan"
);
CREATE TABLE IF NOT EXISTS "audit_logs"(
  "id" integer primary key autoincrement not null,
  "action" varchar not null,
  "subject_type" varchar,
  "subject_id" integer,
  "related_type" varchar,
  "related_id" integer,
  "actor_type" varchar,
  "actor_id" integer,
  "process" varchar not null,
  "metadata" text,
  "created_at" datetime not null,
  "severity" varchar not null default 'info'
);
CREATE INDEX "audit_logs_action_index" on "audit_logs"("action");
CREATE INDEX "audit_logs_created_at_index" on "audit_logs"("created_at");
CREATE INDEX "audit_logs_subject_type_subject_id_index" on "audit_logs"(
  "subject_type",
  "subject_id"
);
CREATE TABLE IF NOT EXISTS "dhcp_pool_statuses"(
  "id" integer primary key autoincrement not null,
  "integration" varchar not null,
  "address_family" varchar not null,
  "total" numeric not null default '0',
  "used" numeric not null default '0',
  "available" numeric not null default '0',
  "utilisation" numeric not null default '0',
  "synced_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "dhcp_pool_statuses_integration_address_family_unique" on "dhcp_pool_statuses"(
  "integration",
  "address_family"
);
CREATE TABLE IF NOT EXISTS "dhcp_sync_states"(
  "id" integer primary key autoincrement not null,
  "integration" varchar not null,
  "address_family" varchar not null,
  "dataset" varchar not null,
  "empty_count" integer not null default '0',
  "last_attempt_at" datetime,
  "last_success_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "dhcp_sync_states_integration_address_family_dataset_unique" on "dhcp_sync_states"(
  "integration",
  "address_family",
  "dataset"
);
CREATE TABLE IF NOT EXISTS "dhcp_snooping_observations"(
  "id" integer primary key autoincrement not null,
  "switch_config_id" integer not null,
  "vlan" integer not null default '0',
  "ip" varchar not null,
  "mac" varchar not null,
  "interface" varchar,
  "expires_at" datetime,
  "observed_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("switch_config_id") references "switch_configs"("id") on delete cascade
);
CREATE UNIQUE INDEX "dhcp_snooping_unique" on "dhcp_snooping_observations"(
  "switch_config_id",
  "vlan",
  "ip",
  "mac"
);
CREATE TABLE IF NOT EXISTS "dhcp_leases"(
  "id" integer primary key autoincrement not null,
  "ip_address_id" integer not null,
  "mac_address_id" integer,
  "hostname" varchar,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "integration" varchar,
  foreign key("mac_address_id") references mac_addresses("id") on delete cascade on update no action,
  foreign key("ip_address_id") references ip_addresses("id") on delete cascade on update no action
);
CREATE INDEX "dhcp_leases_ip_address_id_index" on "dhcp_leases"(
  "ip_address_id"
);
CREATE TABLE IF NOT EXISTS "dhcp_range_records"(
  "id" integer primary key autoincrement not null,
  "integration" varchar,
  "interface" varchar not null default(''),
  "type" varchar not null,
  "subnet" varchar not null,
  "range_from" varchar not null,
  "range_to" varchar not null,
  "prefix" varchar,
  "gateway" varchar,
  "description" varchar,
  "total_addresses" varchar,
  "used_addresses" numeric,
  "utilisation" numeric,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "dhcp_range_records_integration_index" on "dhcp_range_records"(
  "integration"
);
CREATE UNIQUE INDEX "dhcp_range_records_interface_unique" on "dhcp_range_records"(
  "integration",
  "type",
  "interface",
  "subnet",
  "range_from",
  "range_to"
);
CREATE TABLE IF NOT EXISTS "ip_addresses"(
  "id" integer primary key autoincrement not null,
  "address" varchar not null,
  "internet_enabled" tinyint(1),
  "rate_limit_enabled" tinyint(1) not null default('0'),
  "comment" varchar,
  "last_seen_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  "expires_at" datetime,
  "dns_filtering_enabled" tinyint(1) not null default('0')
);
CREATE UNIQUE INDEX "ip_addresses_address_unique" on "ip_addresses"("address");
CREATE UNIQUE INDEX "dhcp_leases_ip_address_id_mac_address_id_unique" on "dhcp_leases"(
  "ip_address_id",
  "mac_address_id"
);

INSERT INTO migrations VALUES(1,'0000_00_00_000000_create_webauthn_credentials',1);
INSERT INTO migrations VALUES(2,'0000_00_00_000000_create_websockets_statistics_entries_table',1);
INSERT INTO migrations VALUES(3,'2014_10_12_000000_create_users_table',1);
INSERT INTO migrations VALUES(4,'2019_08_19_000000_create_failed_jobs_table',1);
INSERT INTO migrations VALUES(5,'2019_12_14_000001_create_personal_access_tokens_table',1);
INSERT INTO migrations VALUES(6,'2023_09_17_212938_create_settings_table',1);
INSERT INTO migrations VALUES(7,'2023_09_17_214801_create_ip_addresses_table',1);
INSERT INTO migrations VALUES(8,'2023_09_18_190344_create_roles_table',1);
INSERT INTO migrations VALUES(9,'2023_09_18_193507_create_role_user_table',1);
INSERT INTO migrations VALUES(10,'2023_09_19_131754_create_auth_providers_table',1);
INSERT INTO migrations VALUES(11,'2023_09_19_134826_create_user_authentications_table',1);
INSERT INTO migrations VALUES(12,'2023_09_19_172201_create_user_ip_addresses_table',1);
INSERT INTO migrations VALUES(13,'2023_10_13_102857_add_recieved_to_ip_addresses',1);
INSERT INTO migrations VALUES(14,'2026_04_10_112048_add_theme_settings',1);
INSERT INTO migrations VALUES(15,'2026_04_10_115420_create_content_blocks_table',1);
INSERT INTO migrations VALUES(16,'2026_04_12_125112_add_expires_at_to_ip_addresses',1);
INSERT INTO migrations VALUES(17,'2026_04_12_182759_create_mac_addresses_table',1);
INSERT INTO migrations VALUES(18,'2026_04_12_182804_add_mac_address_id_to_ip_addresses_table',1);
INSERT INTO migrations VALUES(19,'2026_04_13_000001_consolidate_auth_schema',1);
INSERT INTO migrations VALUES(20,'2026_04_13_004812_create_integration_configs_table',1);
INSERT INTO migrations VALUES(21,'2026_04_13_004956_create_switch_configs_table',1);
INSERT INTO migrations VALUES(22,'2026_04_16_082330_create_capability_assignments_table',1);
INSERT INTO migrations VALUES(23,'2026_04_16_082330_create_connection_test_logs_table',1);
INSERT INTO migrations VALUES(24,'2026_04_16_094808_add_password_to_users_table',1);
INSERT INTO migrations VALUES(25,'2026_04_16_123329_add_response_data_to_connection_test_logs',1);
INSERT INTO migrations VALUES(26,'2026_04_16_140000_add_request_response_detail_to_connection_test_logs',1);
INSERT INTO migrations VALUES(27,'2026_04_16_200220_create_switch_ports_table',1);
INSERT INTO migrations VALUES(28,'2026_04_16_200220_create_switch_sync_runs_table',1);
INSERT INTO migrations VALUES(29,'2026_04_16_200221_create_switch_port_configs_table',1);
INSERT INTO migrations VALUES(30,'2026_04_16_200221_create_switch_port_macs_table',1);
INSERT INTO migrations VALUES(31,'2026_04_19_000001_add_interface_output_to_switch_port_configs_table',1);
INSERT INTO migrations VALUES(32,'2026_04_21_211206_add_grid_columns_to_content_blocks_table',1);
INSERT INTO migrations VALUES(33,'2026_04_21_211711_create_user_parameters_table',1);
INSERT INTO migrations VALUES(34,'2026_04_21_230545_delete_removed_block_types',1);
INSERT INTO migrations VALUES(35,'2026_04_22_151423_rename_policy_columns_and_add_new_fields',1);
INSERT INTO migrations VALUES(36,'2026_04_22_215645_create_pages_table',1);
INSERT INTO migrations VALUES(37,'2026_04_23_300000_create_network_device_tracking_tables',1);
INSERT INTO migrations VALUES(38,'2026_04_25_215416_add_weekly_bandwidth_to_users_table',1);
INSERT INTO migrations VALUES(39,'2026_04_25_222233_add_weekly_received_sent_to_users_table',1);
INSERT INTO migrations VALUES(40,'2026_04_26_013134_rationalise_capabilities_and_drop_ip_bandwidth_columns',1);
INSERT INTO migrations VALUES(41,'2026_04_28_222245_remove_default_librenms_capability_assignments',1);
INSERT INTO migrations VALUES(42,'2026_05_09_000001_make_audit_log_subject_nullable',1);
INSERT INTO migrations VALUES(43,'2026_06_08_000001_create_dhcp_range_records_table',1);
INSERT INTO migrations VALUES(44,'2026_06_08_000002_add_integration_to_dhcp_leases',1);
INSERT INTO migrations VALUES(45,'2026_06_08_000003_create_dhcp_pool_statuses_table',1);
INSERT INTO migrations VALUES(46,'2026_06_08_000004_create_dhcp_sync_states_table',1);
INSERT INTO migrations VALUES(47,'2026_06_08_000005_create_dhcp_snooping_observations_table',1);
INSERT INTO migrations VALUES(48,'2026_06_08_000006_make_dhcp_leases_mac_address_id_nullable',1);
INSERT INTO migrations VALUES(49,'2026_06_09_000001_add_interface_to_dhcp_range_records_unique',1);
INSERT INTO migrations VALUES(50,'2026_06_10_000001_normalize_ipv6_addresses_lowercase',1);
INSERT INTO migrations VALUES(51,'2026_06_10_100000_dedupe_ip_addresses_and_add_unique_index',1);
INSERT INTO migrations VALUES(52,'2026_06_11_000001_change_dhcp_range_records_total_addresses_to_string',1);
INSERT INTO migrations VALUES(53,'2026_06_12_000001_add_severity_to_audit_logs_table',1);
INSERT INTO migrations VALUES(54,'2026_06_12_000002_drop_system_events_table',1);
INSERT INTO migrations VALUES(55,'2026_06_18_120000_make_ip_internet_enabled_nullable',1);
INSERT INTO migrations VALUES(56,'2026_09_28_000001_key_dhcp_leases_on_ip_and_mac',1);
INSERT INTO migrations VALUES(57,'2026_09_28_000002_drop_personal_access_tokens_table',1);
INSERT INTO migrations VALUES(58,'2026_09_28_000003_drop_websockets_statistics_entries_table',1);
