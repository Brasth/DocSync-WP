import type { GoogleAccount, WorkspaceResponse } from '../../api';

export type ActivationAdvisorState =
  | 'site_needs_admin'
  | 'account_disconnected'
  | 'scope_outdated'
  | 'ready_for_source'
  | 'syncing'
  | 'activated'
  | 'needs_attention';

export type ActivationAdvisorAction =
  | 'manage_site_connection'
  | 'contact_admin'
  | 'connect_google'
  | 'reconnect_google'
  | 'create_source'
  | 'wait_for_sync'
  | 'view_sources'
  | 'review_attention';

export type ActivationAdvisor = {
  state: ActivationAdvisorState;
  stage: 'site' | 'account' | 'source';
  blocker: string;
  evidence: string;
  action: ActivationAdvisorAction;
};

type ActivationAdvisorInput = {
  workspace: WorkspaceResponse;
  account: GoogleAccount;
};

/**
 * Maps only server-authoritative facts. The UI supplies translated presentation
 * copy, keeping this decision table deterministic and safe to reuse by role.
 */
export const adviseActivation = ({ workspace, account }: ActivationAdvisorInput): ActivationAdvisor => {
  if (!workspace.siteConnectionReady) {
    return {
      state: 'site_needs_admin',
      stage: 'site',
      blocker: 'site_connection_missing',
      evidence: 'workspace.siteConnectionReady=false',
      action: workspace.canManageSettings ? 'manage_site_connection' : 'contact_admin'
    };
  }

  if (!account.connected) {
    return {
      state: 'account_disconnected',
      stage: 'account',
      blocker: 'google_account_disconnected',
      evidence: 'account.connected=false',
      action: 'connect_google'
    };
  }

  if (!account.hasRequiredScope) {
    return {
      state: 'scope_outdated',
      stage: 'account',
      blocker: 'drive_scope_outdated',
      evidence: 'account.hasRequiredScope=false',
      action: 'reconnect_google'
    };
  }

  if (workspace.sourceSummary.attention > 0) {
    return {
      state: 'needs_attention',
      stage: 'source',
      blocker: 'accessible_source_needs_attention',
      evidence: 'workspace.sourceSummary.attention>0',
      action: 'review_attention'
    };
  }

  if (workspace.sourceSummary.activated) {
    return {
      state: 'activated',
      stage: 'source',
      blocker: 'none',
      evidence: 'workspace.sourceSummary.activated=true',
      action: 'view_sources'
    };
  }

  if (workspace.sourceSummary.syncing > 0) {
    return {
      state: 'syncing',
      stage: 'source',
      blocker: 'first_source_syncing',
      evidence: 'workspace.sourceSummary.syncing>0',
      action: 'wait_for_sync'
    };
  }

  return {
    state: 'ready_for_source',
    stage: 'source',
    blocker: 'first_source_missing',
    evidence: 'connections_ready_without_successful_source',
    action: 'create_source'
  };
};

