<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for mod_masteryagent.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Mastery agent';
$string['modulename'] = 'Mastery agent';
$string['modulenameplural'] = 'Mastery agents';
$string['modulename_help'] = 'The mastery agent assesses a learner through a short, bounded conversation rather than a multiple-choice quiz.

Load one lesson or a whole sequence of lessons from a question-set JSON file. The agent puts each lesson question to the learner, probes where the evidence is thin, challenges misconceptions, and moves straight on to the next lesson when one closes. At the end it reports a score and written feedback for every lesson, plus an overall judgement, and writes the total to the gradebook.

All model requests go through Moodle\'s AI subsystem, so the site\'s configured provider, rate limits and logging apply.';
$string['pluginadministration'] = 'Mastery agent administration';
$string['masteryagent:addinstance'] = 'Add a new mastery agent activity';
$string['masteryagent:view'] = 'View mastery agent activity';
$string['masteryagent:attempt'] = 'Take a mastery assessment';
$string['masteryagent:viewreports'] = 'View mastery assessment transcripts and scores';

// Form.
$string['activityname'] = 'Activity name';
$string['sourceheader'] = 'Lesson source';
$string['lessonfile'] = 'Question set file (JSON)';
$string['lessonfile_help'] = 'Upload the course question-set JSON. The file may contain every lesson; which of them this activity assesses is set below. Re-upload a newer file at any time to update the questions and rubrics without touching anything else.';
$string['lessonkeys'] = 'Lessons to assess';
$string['lessonkeys_help'] = 'Which records in the file this activity assesses, as a comma separated list of lesson or question IDs, for example L01 or L01,L02,L03. The agent works through them in the order given and moves on by itself as each one closes.

Leave this blank to assess every lesson in the file in one sitting.';
$string['currentlesson'] = 'Currently loaded';
$string['lessoncount'] = '{$a} lessons, assessed in order:';
$string['nolessonloaded'] = 'No lesson has been loaded into this activity yet. Edit the settings and upload a question-set file.';
$string['sourcestatus'] = 'Source rubric status: {$a}';
$string['assessmentheader'] = 'Assessment settings';
$string['maxturns'] = 'Maximum replies per lesson';
$string['maxturns_help'] = 'How many replies the learner may give on each lesson before the agent closes it and moves on. The agent may close a lesson sooner if mastery is demonstrated.';
$string['maxgrade'] = 'Maximum score per lesson';
$string['threshold'] = 'Mastery threshold per lesson';
$string['threshold_help'] = 'The per-lesson score at or above which mastery is considered demonstrated. The gradebook total is this activity\'s maximum score multiplied by the number of lessons.';
$string['allowretry'] = 'Allow repeat attempts';
$string['allowretry_help'] = 'Let the learner start a fresh conversation after one has been scored. The gradebook keeps the highest score.';
$string['provisional'] = 'Mark grades as AI-provisional';
$string['provisional_help'] = 'Flags every released grade as an AI judgement awaiting subject matter expert validation. Leave this on until the rubric has been validated.';

// Activity.
$string['introblurb'] = 'This is a short assessment conversation, not a quiz. Answer in your own words. You have up to {$a} replies, and it may end sooner once you have shown what the lesson requires.';
$string['introblurbmulti'] = 'This is an assessment conversation covering {$a->lessons} lessons, not a quiz. Answer in your own words. Each lesson allows up to {$a->turns} replies and may close sooner once you have shown what it requires; the evaluator moves on to the next lesson by itself. You can stop and come back, and your place is kept.';
$string['beforebeginheading'] = 'Before you begin';
$string['beforebeginintro'] = 'The AI evaluator asks about course material. Answer in your own words and explain your reasoning. Follow your course instructions on permitted materials.';
$string['beforebeginsingle'] = 'One lesson, with up to {$a} replies.';
$string['beforebeginmulti'] = '{$a->lessons} lessons, with up to {$a->turns} replies per lesson.';
$string['beforebeginflowsingle'] = 'The lesson may close before you use every reply. Your score is submitted when the lesson closes.';
$string['beforebeginflowmulti'] = 'Lessons may close before you use every reply. The next lesson opens automatically, and your score is submitted when the last lesson closes.';
$string['beforebegingrading'] = 'Each lesson is worth {$a->max} points, with a mastery threshold of {$a->threshold} points. The assessment is worth {$a->total} points in total.';
$string['beforebeginretry'] = 'After an attempt is scored, you can start a new one. The gradebook keeps your highest score.';
$string['beforebeginnoretry'] = 'Repeat attempts are not enabled for this activity.';
$string['beforebeginpause'] = 'Choose Save and leave to keep your place and unsent draft, then return later.';
$string['beforebeginfinish'] = 'Submit final assessment ends your attempt early and scores only answers you have sent. Unanswered lessons contribute 0 points. You cannot resume a submitted attempt.';
$string['beforebeginprovisional'] = 'Your score is an AI-provisional assessment, pending subject matter expert validation.';
$string['begin'] = 'Begin assessment';
$string['progress'] = 'Lesson {$a->position} of {$a->total}:';
$string['lessoncomplete'] = 'That closes lesson {$a->done} of {$a->total}. Next: {$a->next}.';
$string['lessonsinthisactivity'] = 'Lessons assessed by this activity ({$a})';
$string['lessonsmastered'] = 'Lessons at or above the mastery threshold: {$a->mastered} of {$a->total}.';
$string['perlessonscores'] = 'Per-lesson scores';
$string['lesson'] = 'Lesson';
$string['lessonsdone'] = 'Lessons closed';
$string['lessonquestion'] = 'Assessment question';
$string['roleagent'] = 'Evaluator';
$string['rolestudent'] = 'You';
$string['turnsleft'] = 'Replies remaining: {$a}';
$string['lastreplylesson'] = 'This is your last available reply for this lesson. Sending it closes this lesson and opens the next one.';
$string['lastreplyassessment'] = 'This is your last available reply for this lesson. Sending it closes this lesson and submits your final assessment.';
$string['replyplaceholder'] = 'Write your answer here';
$string['sendreply'] = 'Send reply';
$string['finishnow'] = 'End and score now';
$string['saveandleave'] = 'Save and leave';
$string['pausehelp'] = 'Save your place and any unsent answer, then return to the course. You can continue this attempt later without submitting a final grade.';
$string['pausesaved'] = 'Your place and draft have been saved. Open this activity again to continue your assessment.';
$string['draftrestored'] = 'Your saved draft is below. It has not been sent to the evaluator.';
$string['resumeheading'] = 'Welcome back';
$string['resumesaveddraft'] = 'Your saved draft is available in the reply box. It has not been sent to the evaluator.';
$string['resumenodraft'] = 'No saved draft is available. Choose Save and leave to save an unfinished answer.';
$string['resumecontinue'] = 'Continue where I left off';
$string['finishassessment'] = 'Submit final assessment…';
$string['finishprogress'] = 'Lessons completed: {$a->done} of {$a->total}.';
$string['finishconsequences'] = 'This ends your attempt and scores only the answers you have already sent. You cannot resume this attempt after submitting. Choose Save and leave if you want to continue later.';
$string['finishunanswered'] = 'Lessons without a submitted answer: {$a}. These lessons contribute 0 points to your total score.';
$string['finishunsent'] = 'Before submitting your final assessment, send or clear any unsent answer in the reply box. Choose Save and leave to keep it for later.';
$string['confirmfinish'] = 'Yes, submit and score';
$string['finishconfirmationrequired'] = 'Open Submit final assessment and confirm that you want to end and score this attempt.';
$string['tryagain'] = 'Start a new attempt';
$string['scoreline'] = 'Score: {$a->score} of {$a->max}';
$string['attemptscoreline'] = 'This attempt: {$a->score} of {$a->max}';
$string['assessmentcoverage'] = 'Lessons in this attempt: {$a->assessed} assessed, {$a->notassessed} not assessed ({$a->total} total).';
$string['assessmentcoveragerecorded'] = 'Saved lesson records: {$a->assessed} assessed, {$a->notassessed} not assessed. Older records may not include every unanswered lesson.';
$string['assessmentcoveragepartial'] = 'Saved lesson records so far: {$a->assessed} assessed, {$a->notassessed} not assessed.';
$string['lessonnotassessed'] = 'Not assessed — no answer submitted; contributes 0 points.';
$string['lessonnotassessedshort'] = 'Not assessed';
$string['feedbacknavigation'] = 'Jump to lesson feedback';
$string['feedbacklessonfallback'] = 'Lesson {$a}';
$string['highestcompletedscore'] = 'Highest completed score: {$a} points';
$string['highestcompletedscorenone'] = 'No completed score yet.';
$string['highestcompletedscorehelp'] = 'A lower-scoring retry does not replace your highest completed score. Your gradebook grade may include instructor adjustments.';
$string['viewmygradebook'] = 'View my gradebook';
$string['verdictmet'] = 'Mastery threshold met.';
$string['verdictnotmet'] = 'Mastery threshold not yet met ({$a} required).';
$string['provisionalbanner'] = 'AI-provisional assessment, pending subject matter expert validation.';
$string['dimensionbreakdown'] = 'Mastery dimensions';
$string['dimension'] = 'Dimension';
$string['verdict'] = 'Judgement';
$string['comment'] = 'Comment';
$string['verdictmetshort'] = 'Met';
$string['verdictpartial'] = 'Partial';
$string['verdictnotmetshort'] = 'Not met';
$string['nextstep'] = 'Next step:';

// Learner attempt history.
$string['historytitle'] = 'My attempts and feedback';
$string['historyopen'] = 'My attempts and feedback (opens in a new tab)';
$string['historyopenhelp'] = 'Keep this activity tab open to preserve the answer you are writing.';
$string['historyreturnhelp'] = 'If you opened this page while writing an answer, switch back to your original activity tab to continue it.';
$string['historyintro'] = 'Your attempts are listed newest first. Open an attempt to review your submitted answers and saved learning feedback.';
$string['historyempty'] = 'You have no attempts yet. Your attempts will appear here after you begin the activity.';
$string['historyattemptnumber'] = 'Attempt {$a}';
$string['historyreviewattempt'] = 'Review attempt {$a}';
$string['historylatest'] = 'Latest attempt';
$string['historystarted'] = 'Started';
$string['historysubmitted'] = 'Submitted';
$string['historynotsubmitted'] = 'Not submitted';
$string['historynotrecorded'] = 'Not recorded';
$string['historyrecordedscore'] = 'Recorded score';
$string['historypoints'] = '{$a} points';
$string['historyreviewtitle'] = 'Review your attempt';
$string['historybackactivity'] = 'Back to activity';
$string['historybacklist'] = 'Back to my attempts';
$string['historynavigation'] = 'Attempt history navigation';
$string['historyreadonly'] = 'You are reviewing a saved attempt. Your current attempt and grade stay unchanged.';
$string['historynotavailable'] = 'This attempt is not available in your history.';
$string['historyreviewfeedback'] = 'Saved feedback';
$string['historyreviewscore'] = 'Recorded score: {$a} points';
$string['historyreviewscorenote'] = 'This score was recorded when you submitted the attempt. The activity settings may have changed since then.';
$string['historyreviewnoscore'] = 'No final score was saved for this attempt.';
$string['historyreviewunfinished'] = 'This attempt is unfinished. Only submitted messages and feedback from completed lessons are shown.';
$string['historyreviewsummarymissing'] = 'No overall feedback was saved for this attempt.';
$string['historyreviewlessonfeedbackmissing'] = 'No lesson feedback was saved for this attempt.';
$string['historyreviewtranscript'] = 'Saved conversation';
$string['historyreviewnomessages'] = 'No submitted messages were saved for this attempt.';

// Report.
$string['viewreport'] = 'Class attempts and scores';
$string['viewtranscript'] = 'View transcript';
$string['backtoreport'] = 'Back to attempts';
$string['noattempts'] = 'Nobody has attempted this assessment yet.';
$string['status'] = 'Status';
$string['statusinprogress'] = 'In progress';
$string['statusfinished'] = 'Finished';
$string['turnsused'] = 'Replies used';
$string['score'] = 'Score';
$string['finished'] = 'Finished';
$string['finalfeedback'] = 'Final feedback';
$string['evidenceledger'] = 'Evidence ledger';
$string['rubricheading'] = 'Rubric behind this assessment';
$string['strongevidence'] = 'Strong evidence';
$string['partialevidence'] = 'Partial evidence';
$string['misconceptions'] = 'Misconceptions and red flags';
$string['insufficientevidence'] = 'Insufficient evidence conditions';
$string['validationnotes'] = 'Validation notes from the question set';
$string['sourceevidence'] = 'Source evidence';
$string['sourcelink'] = 'open source';

// Errors.
$string['errorbadjson'] = 'That file is not a readable question set. Expected JSON containing a "questions" array.';
$string['errorfilerequired'] = 'Upload a question-set JSON file.';
$string['errorlessonnotfound'] = 'No lesson matching "{$a}" was found in the uploaded file.';
$string['errorlessonrequired'] = 'One or more of those lessons is not in the uploaded file. Available: {$a}';
$string['errorthreshold'] = 'The mastery threshold cannot be higher than the maximum score.';
$string['errormaxgrade'] = 'The maximum score must be at least 1.';
$string['errorprovider'] = 'The AI provider could not be reached: {$a}';
$string['errorempty'] = 'The AI provider returned an empty response.';
$string['errorbadresponse'] = 'The AI provider returned something this activity could not read.';

// AJAX conversation.
$string['yourreply'] = 'Your reply';
$string['replyguidance'] = 'Answer the current question in your own words and explain your reasoning.';
$string['replylimit'] = 'Maximum: {$a} characters. Some symbols and emoji count as more than one character.';
$string['replyremaining'] = 'Characters remaining: {$a}';
$string['replynearlimit'] = 'You are close to the character limit.';
$string['replylimitreached'] = 'Character limit reached.';
$string['replyoverlimit'] = 'Your reply is over the character limit. Shorten it before sending.';
$string['processing'] = 'The evaluator is working. Please wait…';
$string['conversationupdated'] = 'Conversation updated.';
$string['ajaxerror'] = 'The request could not be completed. Review the guidance below before trying again.';
$string['requeststarting'] = 'Starting your attempt. Please wait…';
$string['requestreplypending'] = 'The evaluator is reviewing your answer. Keep this tab open; your answer remains in the reply box while you wait.';
$string['requestfinishing'] = 'Submitting and scoring your final assessment. Please keep this tab open.';
$string['requestpausing'] = 'Saving your place and draft. Please wait for the course page to open.';
$string['requestslow'] = 'Still waiting for a response. Please keep this tab open. You can select and copy any answer in the reply box while you wait.';
$string['requestreplyrecovery'] = 'Your answer is still in the reply box. Review the message above, then choose Send reply to try again. Copy your answer before refreshing or signing in again.';
$string['requestactionrecovery'] = 'Review the message above, then try the action again. Copy any unsent answer before refreshing or signing in again.';
$string['requeststalerecovery'] = 'The latest conversation is shown. Review its messages before sending anything again; your previous request may already have been saved.';
$string['requestdraftrecovery'] = 'Your unsent text is in the recovery box above. Copy it before refreshing or starting another attempt.';
$string['conversationbusy'] = 'This conversation is already processing a request. Please wait and try again.';
$string['conversationchanged'] = 'The conversation has changed, possibly in another tab or after a delayed request. The latest saved conversation is shown. Review it before sending another reply.';
$string['attemptnotavailable'] = 'This action is not available for the current attempt.';
$string['replyrequired'] = 'Enter a reply before sending it.';
$string['replytoolong'] = 'Your reply must contain no more than {$a} characters.';
$string['recovereddraft'] = 'Your unsent draft (copy it before starting another attempt)';

// Student learning plan.
$string['learningstrengths'] = 'What you understand';
$string['learninggaps'] = 'What needs work';
$string['learningnextstep'] = 'What to do next';
$string['learningstrengthsempty'] = 'No specific strengths were recorded for this lesson.';
$string['learninggapsempty'] = 'No specific gaps were recorded. This does not mean every learning objective was assessed.';
$string['learningnextstepempty'] = 'Review the feedback and course readings, then explain the topic again in your own words.';
$string['learningreadings'] = 'Course readings';
$string['learningreadingshelp'] = 'Use these lesson references to revisit the feedback above. Page and section references are shown where available.';
$string['learningunassessedreadingshelp'] = 'Use these saved references to study this lesson. Page and section references are shown where available.';
$string['learningreadingsempty'] = 'No reading references were saved with this result. Check the lesson materials in your course.';
$string['learningbreakdown'] = 'Feedback on assessed skills';
$string['learningskill'] = 'Assessed skill';
$string['learningdimensionfallback'] = 'Assessed skill {$a}';
$string['learningverdictunknown'] = 'Not recorded';

// Printable and copyable learning plan.
$string['learningplantitle'] = 'My learning plan';
$string['learningplanopen'] = 'Print or copy learning plan (opens in a new tab)';
$string['learningplannotavailable'] = 'A learning plan is available after you submit this assessment.';
$string['learningplanback'] = 'Back to attempt feedback';
$string['learningplanprint'] = 'Print / save as PDF';
$string['learningplancopy'] = 'Copy learning plan';
$string['learningplanhelp'] = 'Keep this plan for your next study session. Use your browser\'s Print option to print or save a PDF, or copy the text below.';
$string['learningplanmanual'] = 'Select and copy text';
$string['learningplantext'] = 'Learning plan text';
$string['learningplanmanualhelp'] = 'Select the text in this box and use your device\'s Copy command. With a keyboard, use Ctrl+A then Ctrl+C (Command+A then Command+C on Mac) while in the box.';
$string['learningplancopied'] = 'Learning plan copied.';
$string['learningplancopyfailed'] = 'Automatic copying is unavailable. Open Select and copy text, then use your device\'s Copy command.';
$string['learningplanprintfailed'] = 'The print dialog could not open. Use your browser\'s Print option.';

// Conversation navigation.
$string['conversationnavigation'] = 'Conversation navigation';
$string['conversationsection'] = 'Conversation section {$a}';
$string['currentconversationlesson'] = 'Current lesson';
$string['latestagentmessage'] = 'Latest evaluator message';
$string['revieworiginalscenario'] = 'Review original scenario';
$string['writemyreply'] = 'Write my reply';
$string['jumptoreply'] = 'Jump to your reply';
$string['jumptoresults'] = 'Jump to your results';
$string['assessmentresultsready'] = 'Your assessment results and learning feedback are ready.';
$string['announcementsettings'] = 'Screen-reader updates';
$string['announcementbrief'] = 'Brief notifications';
$string['announcementfull'] = 'Read new feedback in full';
$string['announcementhelp'] = 'Choose how new feedback is announced by your screen reader. You can read every message in the conversation with either option.';
$string['newfeedbackavailable'] = 'New evaluator feedback is available beside your reply box.';

// Question clarification.
$string['clarifyquestion'] = 'Clarify this question';
$string['clarifyquestionhelp'] = 'Get a plain-language restatement of the current question. This does not use a graded reply or submit your answer.';
$string['questionclarification'] = 'Clarification for this question';
$string['roleclarification'] = 'Question clarification (not assessed)';
$string['processingclarify'] = 'Restating the question. Your answer is not being submitted.';
$string['recoveryclarify'] = 'Your reply box is unchanged. Try Clarify this question again, or continue answering the original question.';
$string['clarificationready'] = 'Question clarification is ready. Your graded replies are unchanged.';
