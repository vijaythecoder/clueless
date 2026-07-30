export interface LifecycleCloseRequest {
    generationToken: string;
    requestToken: string;
}

export interface CallLifecycleBridge {
    setBusy: (busy: boolean) => Promise<unknown>;
    completeClose: (request: LifecycleCloseRequest) => Promise<unknown>;
    cancelClose: (request: LifecycleCloseRequest) => Promise<unknown>;
    onCloseRequested: (listener: (request: LifecycleCloseRequest) => void | Promise<void>) => () => void;
}

export interface CallLifecycleGuardOptions<TVisit> {
    bridge?: CallLifecycleBridge;
    stopSession: () => Promise<boolean>;
    onBeforeNavigation: (listener: (visit: TVisit) => boolean) => () => void;
    replayNavigation: (visit: TVisit) => void;
    onError?: (message: string) => void;
}

const SAFE_LIFECYCLE_ERROR = 'Call shutdown did not complete. The current page and window remain open.';

export function useCallLifecycleGuard<TVisit>(options: CallLifecycleGuardOptions<TVisit>) {
    let busy = false;
    let nativeBusyAcknowledged = false;
    let allowNextNavigation = false;
    let activationInFlight: Promise<unknown> | null = null;
    let pendingNavigation: TVisit | null = null;
    let stopInFlight: Promise<boolean> | null = null;

    const reportFailure = () => {
        options.onError?.(SAFE_LIFECYCLE_ERROR);
    };

    const runStop = (): Promise<boolean> => {
        if (stopInFlight) return stopInFlight;

        stopInFlight = Promise.resolve()
            .then(async () => {
                if (activationInFlight) {
                    await Promise.allSettled([activationInFlight]);
                }
                return options.stopSession();
            })
            .catch(() => {
                reportFailure();
                return false;
            })
            .finally(() => {
                stopInFlight = null;
            });

        return stopInFlight;
    };

    const markBusy = async (nextBusy: boolean): Promise<void> => {
        if (nextBusy) {
            busy = true;
            if (nativeBusyAcknowledged) return;
            await options.bridge?.setBusy(true);
            nativeBusyAcknowledged = options.bridge !== undefined;
            return;
        }

        if (!busy && !nativeBusyAcknowledged) return;

        await options.bridge?.setBusy(false);
        nativeBusyAcknowledged = false;
        busy = false;
    };

    const handleCloseRequest = async (request: LifecycleCloseRequest): Promise<void> => {
        if (!busy) {
            await options.bridge?.completeClose(request);
            return;
        }

        if (!(await runStop())) {
            await options.bridge?.cancelClose(request);
            return;
        }

        try {
            await options.bridge?.completeClose(request);
            nativeBusyAcknowledged = false;
            busy = false;
        } catch {
            reportFailure();
            await options.bridge?.cancelClose(request);
        }
    };

    const finishNavigation = async (): Promise<void> => {
        const stopped = await runStop();
        const visit = pendingNavigation;
        pendingNavigation = null;
        if (!stopped || visit === null) return;

        try {
            await markBusy(false);
        } catch {
            reportFailure();
            return;
        }

        allowNextNavigation = true;
        options.replayNavigation(visit);
        queueMicrotask(() => {
            allowNextNavigation = false;
        });
    };

    const handleBeforeNavigation = (visit: TVisit): boolean => {
        if (allowNextNavigation) {
            allowNextNavigation = false;
            return true;
        }
        if (!busy) return true;

        if (pendingNavigation === null) {
            pendingNavigation = visit;
            void finishNavigation();
        }

        return false;
    };

    const runProtectedActivation = <T>(activate: () => Promise<T>, shouldRemainBusy: () => boolean): Promise<T> => {
        const activation = (async () => {
            await markBusy(true);
            try {
                return await activate();
            } finally {
                if (!shouldRemainBusy()) {
                    await markBusy(false);
                }
            }
        })();
        activationInFlight = activation;

        return activation.finally(() => {
            if (activationInFlight === activation) {
                activationInFlight = null;
            }
        });
    };

    const removeCloseListener = options.bridge?.onCloseRequested(handleCloseRequest) ?? (() => undefined);
    const removeNavigationListener = options.onBeforeNavigation(handleBeforeNavigation);

    return {
        isBusy: () => busy,
        markBusy,
        runProtectedActivation,
        dispose: () => {
            removeCloseListener();
            removeNavigationListener();
        },
    };
}
