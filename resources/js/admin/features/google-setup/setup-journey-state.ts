/**
 * Pure setup journey. Screen data decides the step. Nothing is stored as an onboarding flag.
 * Token expiry is not an input: a refresh token keeps the account connected until scope is outdated.
 */

export const SETUP_JOURNEY_STEP_IDS = ['credentials', 'account', 'source'] as const;

export type SetupJourneyStepId = typeof SETUP_JOURNEY_STEP_IDS[number];

export type SetupJourneyPhase = 'credentials' | 'account' | 'source' | 'progress' | 'recovery' | 'maintenance';

export type SetupJourneyRailState = 'complete' | 'current' | 'upcoming' | 'attention';

export type SetupJourneyBasis =
  | 'credentials-missing'
  | 'account-disconnected'
  | 'account-outdated'
  | 'activated'
  | 'source-running'
  | 'watch-running'
  | 'workspace-running'
  | 'source-failed'
  | 'watch-failed'
  | 'workspace-attention'
  | 'awaiting-first-import';

export type SetupJourneySourceInput = {
  syncStatus?: string;
  lastSyncedAt?: string;
} | null;

export type SetupJourneyWatchInput = {
  status?: string;
  importedCount?: number;
  pendingCount?: number;
} | null;

export type SetupJourneyWorkspaceInput = {
  sourceSummary?: {
    activated?: boolean;
    syncing?: number;
    attention?: number;
  };
  folderWatches?: {
    importing?: number;
    attention?: number;
    imported?: number;
  };
} | null;

export type SetupJourneyInput = {
  hasRequiredSettings: boolean;
  accountConnected: boolean;
  accountHasRequiredScope: boolean;
  source?: SetupJourneySourceInput;
  watch?: SetupJourneyWatchInput;
  workspace?: SetupJourneyWorkspaceInput;
};

export type SetupJourneyResolution = {
  phase: SetupJourneyPhase;
  activeStep: SetupJourneyStepId | 'maintenance';
  activated: boolean;
  connectionReady: boolean;
  basis: SetupJourneyBasis;
  rail: Record<SetupJourneyStepId, SetupJourneyRailState>;
};

export type SetupJourneyOverride = 'derived' | 'edit-credentials' | 'maintenance';

export type SetupJourneyView = {
  phase: SetupJourneyPhase;
  pendingActivation: boolean;
  editingCredentials: boolean;
  rail: Record<SetupJourneyStepId, SetupJourneyRailState>;
};

const countOf = (value: number | undefined): number => {
  if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) {
    return 0;
  }

  return Math.floor(value);
};

const textOf = (value: string | undefined): string => value?.trim() ?? '';

export const sourceActivatesJourney = (source: SetupJourneySourceInput): boolean => {
  if (!source) {
    return false;
  }

  return textOf(source.lastSyncedAt) !== '';
};

export const watchActivatesJourney = (watch: SetupJourneyWatchInput): boolean => countOf(watch?.importedCount) >= 1;

const sourceIsRunning = (source: SetupJourneySourceInput): boolean => {
  if (!source || sourceActivatesJourney(source)) {
    return false;
  }

  const status = textOf(source.syncStatus);

  return status === 'syncing' || status === 'queued' || status === 'linked';
};

const watchIsRunning = (watch: SetupJourneyWatchInput): boolean => {
  if (!watch || watchActivatesJourney(watch)) {
    return false;
  }

  const status = textOf(watch.status);

  return status === 'importing' || status === 'watching' || countOf(watch.pendingCount) > 0;
};

const sourceNeedsRecovery = (source: SetupJourneySourceInput): boolean => {
  return Boolean(source) && !sourceActivatesJourney(source) && !sourceIsRunning(source);
};

const watchNeedsRecovery = (watch: SetupJourneyWatchInput): boolean => {
  return Boolean(watch) && !watchActivatesJourney(watch) && !watchIsRunning(watch);
};

const phaseStep = (phase: SetupJourneyPhase): SetupJourneyStepId | 'maintenance' => {
  if (phase === 'maintenance') {
    return 'maintenance';
  }
  if (phase === 'credentials') {
    return 'credentials';
  }

  if (phase === 'account') {
    return 'account';
  }

  return 'source';
};

const buildRail = (
  phase: SetupJourneyPhase,
  credentialsComplete: boolean,
  accountComplete: boolean,
  sourceComplete: boolean
): Record<SetupJourneyStepId, SetupJourneyRailState> => {
  if (phase === 'maintenance') {
    return {
      credentials: credentialsComplete ? 'complete' : 'upcoming',
      account: accountComplete ? 'complete' : 'upcoming',
      source: sourceComplete ? 'complete' : 'upcoming'
    };
  }

  const activeStep = phaseStep(phase);
  const complete: Record<SetupJourneyStepId, boolean> = {
    credentials: credentialsComplete,
    account: accountComplete,
    source: sourceComplete
  };

  return SETUP_JOURNEY_STEP_IDS.reduce((rail, stepId) => {
    if (stepId === activeStep) {
      rail[stepId] = stepId === 'source' && phase === 'recovery' ? 'attention' : 'current';
      return rail;
    }

    rail[stepId] = complete[stepId] ? 'complete' : 'upcoming';
    return rail;
  }, {} as Record<SetupJourneyStepId, SetupJourneyRailState>);
};

export const resolveSetupJourney = (input: SetupJourneyInput): SetupJourneyResolution => {
  const source = input.source ?? null;
  const watch = input.watch ?? null;
  const workspace = input.workspace ?? null;
  const folderImported = countOf(workspace?.folderWatches?.imported);
  const workspaceActivated = Boolean(workspace?.sourceSummary?.activated) || folderImported >= 1;
  const activated = sourceActivatesJourney(source) || watchActivatesJourney(watch) || workspaceActivated;
  const connectionReady = input.hasRequiredSettings && input.accountConnected && input.accountHasRequiredScope;

  let phase: SetupJourneyPhase = 'source';
  let basis: SetupJourneyBasis = 'awaiting-first-import';

  if (!input.hasRequiredSettings) {
    phase = 'credentials';
    basis = 'credentials-missing';
  } else if (!input.accountConnected) {
    phase = 'account';
    basis = 'account-disconnected';
  } else if (!input.accountHasRequiredScope) {
    phase = 'account';
    basis = 'account-outdated';
  } else if (activated) {
    phase = 'maintenance';
    basis = 'activated';
  } else if (sourceIsRunning(source)) {
    phase = 'progress';
    basis = 'source-running';
  } else if (watchIsRunning(watch)) {
    phase = 'progress';
    basis = 'watch-running';
  } else if (!source && !watch && (countOf(workspace?.sourceSummary?.syncing) > 0 || countOf(workspace?.folderWatches?.importing) > 0)) {
    phase = 'progress';
    basis = 'workspace-running';
  } else if (sourceNeedsRecovery(source)) {
    phase = 'recovery';
    basis = 'source-failed';
  } else if (watchNeedsRecovery(watch)) {
    phase = 'recovery';
    basis = 'watch-failed';
  } else if (!source && !watch && (countOf(workspace?.sourceSummary?.attention) > 0 || countOf(workspace?.folderWatches?.attention) > 0)) {
    phase = 'recovery';
    basis = 'workspace-attention';
  }

  return {
    phase,
    activeStep: phaseStep(phase),
    activated: phase === 'maintenance',
    connectionReady,
    basis,
    rail: buildRail(phase, input.hasRequiredSettings, connectionReady, phase === 'maintenance')
  };
};

export const resolveSetupJourneyView = (
  resolution: SetupJourneyResolution,
  override: SetupJourneyOverride = 'derived',
  options: { forceCredentials?: boolean } = {}
): SetupJourneyView => {
  if (options.forceCredentials || override === 'edit-credentials') {
    const editingCredentials = override === 'edit-credentials' && resolution.phase !== 'credentials';

    if (!editingCredentials) {
      return {
        phase: 'credentials',
        pendingActivation: false,
        editingCredentials: false,
        rail: resolution.rail
      };
    }

    const demote = (state: SetupJourneyRailState): SetupJourneyRailState => {
      if (state === 'current' || state === 'attention') {
        return 'upcoming';
      }

      return state;
    };

    return {
      phase: 'credentials',
      pendingActivation: false,
      editingCredentials: true,
      rail: {
        credentials: 'current',
        account: demote(resolution.rail.account),
        source: demote(resolution.rail.source)
      }
    };
  }

  const canOpenMaintenance = resolution.connectionReady && resolution.phase !== 'credentials' && resolution.phase !== 'account';

  if (override === 'maintenance' && canOpenMaintenance && resolution.phase !== 'maintenance') {
    return {
      phase: 'maintenance',
      pendingActivation: !resolution.activated,
      editingCredentials: false,
      rail: resolution.rail
    };
  }

  return {
    phase: resolution.phase,
    pendingActivation: false,
    editingCredentials: false,
    rail: resolution.rail
  };
};

export const abbreviateClientId = (clientId: string): string => {
  const value = clientId.trim();

  if (value.length <= 18) {
    return value;
  }

  if (value.endsWith('.apps.googleusercontent.com')) {
    return `${value.slice(0, 8)}…apps.googleusercontent.com`;
  }

  return `${value.slice(0, 8)}…${value.slice(-4)}`;
};

export const readSettingsIdentity = (settings: {
  oauthCredentialsSavedAt?: string | null;
  oauthCredentialsSavedDateLabel?: string | null;
  currentUserDisplayName?: string | null;
}): { savedAt: string | null; savedDateLabel: string | null; displayName: string } => ({
  savedAt: typeof settings.oauthCredentialsSavedAt === 'string' && settings.oauthCredentialsSavedAt.trim() !== ''
    ? settings.oauthCredentialsSavedAt
    : null,
  savedDateLabel: typeof settings.oauthCredentialsSavedDateLabel === 'string' && settings.oauthCredentialsSavedDateLabel.trim() !== ''
    ? settings.oauthCredentialsSavedDateLabel
    : null,
  displayName: typeof settings.currentUserDisplayName === 'string' ? settings.currentUserDisplayName.trim() : ''
});
