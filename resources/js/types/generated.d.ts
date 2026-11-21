declare namespace App {
namespace Checks {
export type ArgumentType = 'int' | 'string' | 'bool' | 'intList' | 'stringList' | 'select';
}
namespace Data {
export type ActivityItemData = {
id: number,
category: App.Enums.ActivityCategory,
type: string,
summary: string,
user: string | null,
created_at: string,
url: string | null,
};
export type AuthUserData = {
id: number,
name: string,
role: App.Enums.UserRole,
};
export type ChoiceData = {
id: number,
label: string,
};
export type ChoiceRowData = {
id: number,
label: string,
position: number,
servers_count: number,
};
export type DomainFormData = {
id: number | null,
name: string,
notes: string | null,
};
export type DomainRowData = {
id: number,
name: string,
status: App.Enums.Status,
expires_at: string | null,
expires_in_days: number | null,
registrar: string | null,
failing_count: number,
warning_count: number,
sites: App.Data.DomainSiteData[],
deleted: boolean,
};
export type DomainSiteData = {
id: number,
hostname: string,
description: string | null,
github_url: string | null,
status: App.Enums.Status,
serving_path: App.Data.ServingPathData,
server_id: number | null,
};
export type ExternalServiceFormData = {
id: number | null,
name: string,
kind: App.Enums.ExternalServiceKind,
ranges: string[],
ranges_source: App.Enums.RangeSource | null,
cname_suffixes: string[],
response_headers: string[],
notes: string | null,
};
export type ExternalServiceRowData = {
id: number,
name: string,
kind: App.Enums.ExternalServiceKind,
ranges_count: number,
ranges_source: App.Enums.RangeSource | null,
sites_count: number,
deleted: boolean,
};
export type FilterOptionData = {
value: string,
label: string,
};
export type MinimumVersionRowData = {
id: number,
software: string,
branch: string,
label: string,
min_version: string,
cve_url: string | null,
note: string | null,
servers_below: {
id: number,
name: string,
version: string,
}[],
set_by: string,
updated_at: string,
};
export type OptionData = {
id: number,
label: string,
};
export type ProfileData = {
name: string,
email: string,
};
export type SearchResultData = {
type: App.Enums.SearchResultType,
id: number,
label: string,
context: string,
status: App.Enums.Status | null,
url: string,
};
export type ServedSiteData = {
id: number,
hostname: string,
description: string | null,
status: App.Enums.Status,
serving_path: App.Data.ServingPathData,
};
export type ServerAddressData = {
id: number,
ip: string,
version: number,
is_primary: boolean,
};
export type ServerData = {
id: number,
name: string,
hostname: string | null,
provider: string | null,
os: string | null,
notes: string | null,
status: App.Enums.Status,
addresses: App.Data.ServerAddressData[],
backup_locations: string[],
deleted: boolean,
};
export type ServerFormData = {
id: number | null,
name: string,
hostname: string | null,
provider: string | null,
os_choice_id: number | null,
notes: string | null,
addresses: {
ip: string,
is_primary: boolean,
}[],
backup_location_ids: number[],
versions_url: string | null,
versions_token_masked: string | null,
};
export type ServerRowData = {
id: number,
name: string,
hostname: string | null,
status: App.Enums.Status,
primary_ip: string | null,
os: string | null,
sites_count: number,
backup_locations: string[],
deleted: boolean,
versions_configured: boolean,
versions: App.Data.ServerVersionData[],
};
export type ServerVersionData = {
software: string,
branch: string,
label: string,
version: string,
package_version: string | null,
min_version: string | null,
below_minimum: boolean,
read_at: string,
details: Record<string, any> | null,
};
export type ServerVersionsPanelData = {
configured: boolean,
endpoint_url: string | null,
token_masked: string | null,
read_at: string | null,
fetched_at: string | null,
os_reported: string | null,
os_set: string | null,
last_message: string | null,
versions: App.Data.ServerVersionData[],
};
export type ServingPathData = {
front: string | null,
backend: string | null,
};
export type SiteData = {
id: number,
hostname: string,
url: string,
description: string | null,
github_url: string | null,
notes: string | null,
domain_id: number,
domain: string,
serving_path: App.Data.ServingPathData,
front_service_id: number | null,
server_id: number | null,
status: App.Enums.Status,
deleted: boolean,
};
export type SiteFormData = {
id: number | null,
domain_id: number | null,
hostname: string,
scheme: string,
port: number | null,
front_service_id: number | null,
server_id: number | null,
description: string | null,
github_url: string | null,
notes: string | null,
};
export type SiteRowData = {
id: number,
hostname: string,
description: string | null,
domain_id: number,
domain: string,
serving_path: App.Data.ServingPathData,
server_id: number | null,
status: App.Enums.Status,
deleted: boolean,
};
export type TrafficGroupData = {
name: string,
kind: App.Enums.ExternalServiceKind | null,
sites_count: number,
first_site: string,
};
export type UserRowData = {
id: number,
name: string,
email: string,
role: App.Enums.UserRole,
created_at: string | null,
is_you: boolean,
};
export type VersionsTestReadingData = {
software: string,
version: string,
package: string | null,
};
export type VersionsTestResultData = {
ok: boolean,
error: string | null,
generated_at: string | null,
hostname: string | null,
readings: App.Data.VersionsTestReadingData[],
};
export type VersionsTokenData = {
token: string,
last4: string,
};
namespace Dashboard {
export type DashboardData = {
totals: App.Data.Dashboard.TotalsData,
issues: App.Data.Dashboard.IssueGroupData[],
muted: App.Data.Dashboard.IssueGroupData[],
pending_count: number,
resolved: App.Data.Dashboard.ResolvedItemData[],
software: App.Data.Dashboard.SoftwareMinimumRowData[],
expiring: App.Data.Dashboard.ExpiringItemData[],
changes: App.Data.ActivityItemData[],
system: App.Data.Dashboard.SystemHealthData,
};
export type ExpiringItemData = {
kind: string,
name: string,
expires_at: string,
days: number,
url: string,
};
export type IncidentItemData = {
id: number,
title: string,
message: string,
severity: App.Enums.Status,
opened_at: string,
acknowledged_by: string | null,
recovering: boolean,
serving_path: string | null,
url: string,
};
export type IssueGroupData = {
resource: App.Data.Dashboard.ResourceRefData,
incidents: App.Data.Dashboard.IncidentItemData[],
children: App.Data.Dashboard.IncidentItemData[],
};
export type ResolvedItemData = {
id: number,
title: string,
opened_at: string,
resolved_at: string,
children_count: number,
url: string,
};
export type ResourceRefData = {
type: string,
id: number,
name: string,
status: App.Enums.Status,
url: string,
};
export type SoftwareMinimumRowData = {
software: string,
branch: string,
min_version: string | null,
below: {
server: string,
version: string,
}[],
};
export type StatusCountsData = {
total: number,
ok: number,
warning: number,
error: number,
unknown: number,
};
export type SystemHealthData = {
last_dispatch_at: string | null,
checks_running: boolean,
stale_monitors: number,
network_healthy: boolean | null,
};
export type TotalsData = {
servers: App.Data.Dashboard.StatusCountsData,
domains: App.Data.Dashboard.StatusCountsData,
sites: App.Data.Dashboard.StatusCountsData,
sites_unassigned: number,
servers_below_minimum: number,
};
}
namespace Detection {
export type BackendCandidateData = {
server_id: number,
name: string,
sibling_count: number,
};
export type BackendFindingData = {
value: App.Data.Detection.DetectedServerData | null,
confidence: App.Enums.Confidence,
explanation: string,
};
export type DetectedDomainData = {
name: string,
display_name: string,
existing_id: number | null,
site_count: number,
registry: App.Data.Detection.RegistryPreviewData | null,
};
export type DetectedServerData = {
server_id: number,
name: string,
};
export type DetectedServiceData = {
service_id: number,
name: string,
};
export type DnsFindingData = {
value: App.Data.Detection.DnsRecordsData,
confidence: App.Enums.Confidence,
explanation: string,
};
export type DnsRecordsData = {
ips: string[],
cname_chain: string[],
};
export type DomainFindingData = {
value: App.Data.Detection.DetectedDomainData,
confidence: App.Enums.Confidence,
explanation: string,
};
export type DomainLookupData = {
name: string,
display_name: string,
existing_id: number | null,
registry: App.Data.Detection.RegistryPreviewData | null,
};
export type DuplicateSiteData = {
site_id: number,
serving_path: string,
trashed: boolean,
};
export type FrontFindingData = {
value: App.Data.Detection.DetectedServiceData | null,
confidence: App.Enums.Confidence,
explanation: string,
};
export type ProbeSummaryData = {
http: string,
http_status: App.Enums.Status,
tls: string | null,
tls_status: App.Enums.Status | null,
redirect: string,
redirect_status: App.Enums.Status,
final_url: string | null,
};
export type ProposedMonitorData = {
checker: string,
label: string | null,
arguments: Record<string, any>,
};
export type RegistryPreviewData = {
source: string,
server: string | null,
found: boolean,
registrar: string | null,
expires_at: string | null,
nameservers: string[],
statuses: string[],
error: string | null,
};
export type SiteDetectionData = {
input: string,
hostname: string,
display_hostname: string,
scheme: string,
port: number | null,
path: string,
duplicate: App.Data.Detection.DuplicateSiteData | null,
domain: App.Data.Detection.DomainFindingData | null,
dns: App.Data.Detection.DnsFindingData | null,
front: App.Data.Detection.FrontFindingData | null,
backend: App.Data.Detection.BackendFindingData | null,
backend_candidates: App.Data.Detection.BackendCandidateData[],
unknown_ip: string | null,
probe: App.Data.Detection.ProbeSummaryData | null,
offer_www: string | null,
monitors: App.Data.Detection.ProposedMonitorData[],
};
}
namespace Import {
export type ImportResultData = {
input: string,
hostname: string | null,
outcome: App.Enums.ImportOutcome,
message: string,
site_id: number | null,
};
}
namespace Incidents {
export type IncidentDetailData = {
incident: App.Data.Incidents.IncidentRowData,
max_severity: App.Enums.Status,
occurrences: number,
last_seen_at: string,
acknowledged_at: string | null,
monitor_id: number,
monitor_name: string,
monitor_deleted: boolean,
serving_path: App.Data.ServingPathData | null,
first_result: App.Data.Incidents.IncidentResultData,
last_result: App.Data.Incidents.IncidentResultData,
events: App.Data.Incidents.IncidentEventData[],
parent: App.Data.Incidents.IncidentRowData | null,
children: App.Data.Incidents.IncidentRowData[],
};
export type IncidentEventData = {
id: number,
type: string,
severity: App.Enums.Status,
message: string,
user: string | null,
notify: boolean,
notified_at: string | null,
suppressed_reason: string | null,
created_at: string,
};
export type IncidentResultData = {
status: App.Enums.Status,
message: string,
data: Record<string, any>,
};
export type IncidentRowData = {
id: number,
title: string,
severity: App.Enums.Status,
state: string,
message: string,
resource: App.Data.Monitors.MonitorResourceData,
checker: string,
opened_at: string,
resolved_at: string | null,
resolution: string | null,
acknowledged_by: string | null,
parent_id: number | null,
children_count: number,
recovering: boolean,
muted: boolean,
};
}
namespace Monitors {
export type ArgumentSchemaData = {
type: string,
name: string,
label: string,
help: string | null,
nullable: boolean,
options: Record<string, string> | null,
default: any,
};
export type CheckerOptionData = {
key: string,
name: string,
interval: number,
failures_before_incident: number,
successes_before_resolve: number,
arguments: App.Data.Monitors.ArgumentSchemaData[],
};
export type MonitorDetailData = {
monitor: App.Data.Monitors.MonitorRowData,
settings: App.Data.Monitors.MonitorSettingData[],
consecutive_failures: number,
consecutive_successes: number,
consecutive_unknowns: number,
last_definitive_at: string | null,
stale_at: string | null,
runs: App.Data.Monitors.MonitorRunData[],
form: App.Data.Monitors.MonitorFormData,
checker: App.Data.Monitors.CheckerOptionData | null,
};
export type MonitorFormData = {
id: number | null,
monitorable_type: string,
monitorable_id: number,
checker: string,
label: string | null,
arguments: Record<string, any>,
interval_minutes: number | null,
failures_before_incident: number | null,
successes_before_resolve: number | null,
enabled: boolean,
};
export type MonitorResourceData = {
type: string,
id: number,
name: string,
};
export type MonitorRowData = {
id: number,
checker: string,
name: string,
resource: App.Data.Monitors.MonitorResourceData,
enabled: boolean,
muted_until: string | null,
status: App.Enums.Status,
pending: boolean,
recovering: boolean,
open_incident_id: number | null,
last_result_status: App.Enums.Status | null,
message: string | null,
duration_ms: number | null,
checked_at: string | null,
next_run_at: string | null,
interval: number,
};
export type MonitorRunData = {
id: number,
status: App.Enums.Status,
duration_ms: number,
message: string | null,
checked_at: string,
};
export type MonitorSettingData = {
label: string,
value: string,
inherited: boolean,
};
}
}
namespace Enums {
export type ActivityCategory = 'status' | 'inventory' | 'detected';
export type ChoiceList = 'os' | 'backup_location';
export type Confidence = 'detected' | 'suggested' | 'not_found';
export type ExternalServiceKind = 'cdn' | 'proxy' | 'other';
export type ImportOutcome = 'created' | 'needs_review' | 'skipped';
export type IncidentEventType = 'opened' | 'escalated' | 'deescalated' | 'acknowledged' | 'reminder' | 'resolved';
export type IncidentResolution = 'recovered' | 'monitor_disabled' | 'monitor_deleted' | 'resource_deleted';
export type RangeSource = 'cloudflare' | 'fastly' | 'cloudfront';
export type SearchResultType = 'server' | 'external_service' | 'domain' | 'site';
export type Software = 'os' | 'kernel' | 'nginx' | 'php' | 'mysql' | 'openssl';
export type Status = 'ok' | 'warning' | 'error' | 'unknown';
export type UserRole = 'admin' | 'reader';
}
}
declare namespace Illuminate {
export type CursorPaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
path: string,
per_page: number,
next_cursor: string | null,
next_page_url: string | null,
prev_cursor: string | null,
prev_page_url: string | null,
},
};
export type CursorPaginatorInterface<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type LengthAwarePaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
total: number,
current_page: number,
first_page_url: string,
from: number | null,
last_page: number,
last_page_url: string,
next_page_url: string | null,
path: string,
per_page: number,
prev_page_url: string | null,
to: number | null,
},
};
export type LengthAwarePaginatorInterface<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
declare namespace Spatie {
namespace LaravelData {
export type CursorPaginatedDataCollection<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type PaginatedDataCollection<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
}
