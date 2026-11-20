declare namespace App {
namespace Data {
export type ActivityItemData = {
id: number,
category: App.Enums.ActivityCategory,
type: string,
summary: string,
user: string | null,
created_at: string,
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
export type ProfileData = {
name: string,
email: string,
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
export type TrafficGroupData = {
name: string,
kind: App.Enums.ExternalServiceKind | null,
sites_count: number,
first_site: string,
};
}
namespace Enums {
export type ActivityCategory = 'status' | 'inventory' | 'detected';
export type ChoiceList = 'os' | 'backup_location';
export type Confidence = 'detected' | 'suggested' | 'not_found';
export type ExternalServiceKind = 'cdn' | 'proxy' | 'other';
export type RangeSource = 'cloudflare' | 'fastly' | 'cloudfront';
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
