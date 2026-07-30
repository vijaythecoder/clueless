import { useCallLifecycleGuard, type CallLifecycleGuardOptions } from '@/composables/useCallLifecycleGuard';

export interface InertiaLifecycleRouter<TVisit extends { url: unknown }> {
    on: (event: 'before', listener: (event: { detail: { visit: TVisit } }) => boolean | void) => () => void;
    visit: (url: TVisit['url'], options: Omit<TVisit, 'url'>) => void;
}

interface CallLifecycleAdapterOptions<TVisit extends { url: unknown }>
    extends Pick<CallLifecycleGuardOptions<TVisit>, 'bridge' | 'stopSession' | 'onError'> {
    router: InertiaLifecycleRouter<TVisit>;
}

export function useCallLifecycleAdapter<TVisit extends { url: unknown }>(options: CallLifecycleAdapterOptions<TVisit>) {
    return useCallLifecycleGuard<TVisit>({
        bridge: options.bridge,
        stopSession: options.stopSession,
        onBeforeNavigation: (listener) => options.router.on('before', (event) => listener(event.detail.visit)),
        replayNavigation: (visit) => {
            const { url, ...visitOptions } = visit;
            options.router.visit(url, visitOptions);
        },
        onError: options.onError,
    });
}
