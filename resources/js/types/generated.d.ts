declare namespace App {
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
}
namespace Enums {
export type ActivityCategory = 'status' | 'inventory' | 'detected';
export type ChoiceList = 'os' | 'backup_location';
export type Confidence = 'detected' | 'suggested' | 'not_found';
export type ExternalServiceKind = 'cdn' | 'proxy' | 'other';
export type ImportOutcome = 'created' | 'needs_review' | 'skipped';
export type RangeSource = 'cloudflare' | 'fastly' | 'cloudfront';
export type SearchResultType = 'server' | 'external_service' | 'domain' | 'site';
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
