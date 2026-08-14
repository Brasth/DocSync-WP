import { request } from './client';

export type FeedbackType = 'bug' | 'feature' | 'question';

export type FeedbackSubmission = {
  type: FeedbackType;
  title: string;
  description: string;
};

export type FeedbackResponse = {
  issueNumber: number;
  issueUrl: string;
};

export const submitFeedback = (submission: FeedbackSubmission): Promise<FeedbackResponse> => {
  return request<FeedbackResponse>('feedback', {
    data: submission,
    method: 'POST'
  });
};
