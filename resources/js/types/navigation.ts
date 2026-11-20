import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};

/** A sidebar entry. `href` is missing while its page doesn't exist yet: the item shows disabled. */
export type SidebarNavItem = {
    title: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
    count?: number;
};
