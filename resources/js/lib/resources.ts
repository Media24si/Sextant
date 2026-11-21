import DomainController from '@/actions/App/Http/Controllers/DomainController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';

/** The page of a server, domain or site. */
export function resourceHref(resource: { type: string; id: number }) {
    switch (resource.type) {
        case 'server':
            return ServerController.show(resource.id);
        case 'domain':
            return DomainController.show(resource.id);
        case 'site':
            return SiteController.show(resource.id);
        default:
            return null;
    }
}
