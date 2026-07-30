<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class MeetingTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Sales Discovery Call',
                'description' => 'Analyze sales discovery calls to identify pain points, budget, and decision criteria',
                'prompt' => 'You are analyzing a sales discovery call for Clueless, an AI-powered meeting assistant. 

PRODUCT CONTEXT:
- Product: Clueless - Real-time meeting transcription and analysis
- Pricing: $49/user/month (teams 5-20), $39/user/month (20+ users)
- Key Features: Live transcription, action item extraction, sentiment analysis, integrations
- Value Prop: Never miss important details, 100% meeting capture, saves 5 hours/week

MEETING CONTEXT:
- Type: Sales Discovery Call
- Language: English
- Participants: Sales rep and potential customer

ANALYSIS RULES:
1. Identify customer pain points related to:
   - Meeting inefficiency
   - Information loss
   - Follow-up challenges
   - Team collaboration

2. Track budget indicators:
   - Team size mentions
   - Current tool spending
   - Budget constraints

3. Extract key information:
   - Decision timeline
   - Decision makers
   - Success criteria
   - Competitive solutions

When pricing is discussed, provide full context:
- Starter: $49/user/month (5-20 users)
- Team: $39/user/month (20+ users)  
- Enterprise: Custom pricing
- Annual discount: 20% off
- Free trial: 14 days

Output insights about qualification, fit score, and next steps.',
                'variables' => [
                    'product_name' => 'Clueless',
                    'base_price' => '$49/user/month',
                    'team_price' => '$39/user/month',
                    'trial_length' => '14 days',
                ],
                'talking_points' => [
                    'How do you currently handle meeting notes?',
                    'What challenges do you face with action item tracking?',
                    'How many meetings does your team have weekly?',
                    'What happens when key people miss meetings?',
                    'How do you ensure nothing falls through the cracks?',
                ],
                'icon' => 'Phone',
                'category' => 'sales_coach',
                'is_system' => true,
            ],
            [
                'name' => 'Product Demo Meeting',
                'description' => 'Track feature interest and engagement during product demonstrations',
                'prompt' => 'You are analyzing a product demo meeting for Clueless meeting assistant.

PRODUCT CONTEXT:
- Live transcription with 99% accuracy
- Real-time action item detection
- Automatic meeting summaries
- Sentiment and engagement tracking
- Integrations: Slack, Notion, CRM systems
- Security: SOC2 compliant, end-to-end encryption

MEETING CONTEXT:
- Type: Product Demonstration
- Focus: Showing key features and value
- Goal: Convert interest to commitment

ANALYSIS RULES:
1. Track feature interest level (1-10) for:
   - Live transcription
   - Action item extraction
   - Meeting summaries
   - Integrations
   - Analytics dashboard

2. Monitor engagement indicators:
   - Questions asked
   - Concerns raised
   - "Wow" moments
   - Objections

3. Identify buying signals:
   - "How soon can we start?"
   - "What about our security requirements?"
   - "Can we get a pilot going?"

4. Note technical requirements discussed

When showing ROI, emphasize:
- 5 hours saved per person per week
- 90% reduction in missed action items
- 50% faster meeting follow-up
- 100% meeting capture rate',
                'variables' => [
                    'demo_length' => '30 minutes',
                    'key_features' => '5 core features',
                    'roi_multiplier' => '10x in 3 months',
                ],
                'talking_points' => [
                    'Live transcription accuracy demonstration',
                    'Action item extraction in real-time',
                    'Integration with existing tools',
                    'Security and compliance features',
                    'Team collaboration capabilities',
                    'Analytics and insights dashboard',
                ],
                'icon' => 'Monitor',
                'category' => 'sales_coach',
                'is_system' => true,
            ],
            [
                'name' => 'Pricing Negotiation',
                'description' => 'Handle pricing discussions and negotiate terms effectively',
                'prompt' => 'You are analyzing a pricing negotiation for Clueless meeting assistant.

PRICING STRUCTURE:
- Starter (5-20 users): $49/user/month
- Team (20-50 users): $39/user/month  
- Enterprise (50+ users): $29/user/month
- Annual payment: 20% discount
- Quarterly payment: 10% discount
- Setup/training: Included free
- Trial period: 14 days full access

MEETING CONTEXT:
- Type: Pricing Negotiation
- Decision stage: Final evaluation
- Competition: Manual notes, Otter.ai, Fireflies

ANALYSIS RULES:
1. Track pricing objections:
   - "Too expensive"
   - "Budget constraints"
   - "Need approval"
   - "Comparing options"

2. Calculate ROI for their specific case:
   - Hours saved = team size × 5 hours/week
   - Cost per hour saved
   - Productivity gains
   - Error reduction value

3. Identify negotiation levers:
   - Contract length
   - Payment terms
   - User volume
   - Feature requirements

4. Note competitor comparisons:
   - Clueless vs manual: 10x more effective
   - Clueless vs Otter: Real-time analysis
   - Clueless vs Fireflies: Better integrations

When discussing price, always connect to value:
- "At $49/user, you save $500/month per user in productivity"
- "The annual plan brings it down to just $39/user"
- "Most teams see ROI within 2 weeks"',
                'variables' => [
                    'starter_price' => '$49/user/month',
                    'team_price' => '$39/user/month',
                    'enterprise_price' => '$29/user/month',
                    'annual_discount' => '20%',
                ],
                'talking_points' => [
                    'ROI calculation based on time saved',
                    'Comparison with manual note-taking costs',
                    'Volume discounts available',
                    'Flexible payment terms',
                    'Success stories from similar companies',
                    'Risk-free trial period',
                ],
                'icon' => 'DollarSign',
                'category' => 'sales_coach',
                'is_system' => true,
            ],
            [
                'name' => 'Customer Success Check-in',
                'description' => 'Monitor customer health and identify expansion opportunities',
                'prompt' => 'You are analyzing a customer success check-in for Clueless users.

CUSTOMER CONTEXT:
- Current product: Clueless meeting assistant
- Monitoring: Usage, satisfaction, expansion potential
- Goal: Ensure success and identify growth

MEETING CONTEXT:
- Type: Quarterly Business Review
- Participants: Customer success manager and customer
- Focus: Value realization and optimization

ANALYSIS RULES:
1. Assess current usage:
   - Active users vs licensed users
   - Meeting coverage percentage
   - Feature adoption rates
   - Integration usage

2. Identify satisfaction indicators:
   - Positive feedback
   - Feature requests
   - Pain points
   - Team adoption challenges

3. Spot expansion opportunities:
   - Additional teams needing Clueless
   - Advanced features interest
   - Integration needs
   - Training requirements

4. Track success metrics:
   - Time saved per week
   - Action items completed
   - Meeting efficiency improvements
   - Team collaboration enhancement

Health scoring:
- Green: >80% adoption, positive feedback
- Yellow: 50-80% adoption, mixed feedback
- Red: <50% adoption, concerns raised

When discussing expansion:
- Additional teams: Same pricing tier applies
- Advanced analytics: +$10/user/month
- Custom integrations: Enterprise plan
- Priority support: +$500/month',
                'variables' => [
                    'health_score' => 'Green/Yellow/Red',
                    'adoption_rate' => 'X% of licensed users',
                    'expansion_potential' => 'Additional teams/features',
                ],
                'talking_points' => [
                    'Usage metrics and adoption rates',
                    'Success stories within their org',
                    'Optimization opportunities',
                    'New features and updates',
                    'Team expansion possibilities',
                    'Advanced training options',
                ],
                'icon' => 'Heart',
                'category' => 'customer_success',
                'is_system' => true,
            ],
            [
                'name' => 'Technical Integration Call',
                'description' => 'Discuss API, security, and implementation requirements',
                'prompt' => 'You are analyzing a technical integration call for Clueless.

TECHNICAL CONTEXT:
- APIs: REST API, Webhooks, Real-time streams
- Security: SOC2, HIPAA compliant, E2E encryption
- Integrations: Slack, Teams, Zoom, 50+ apps
- Deployment: Cloud SaaS, no on-prem required

MEETING CONTEXT:
- Type: Technical Deep Dive
- Participants: Technical teams
- Focus: Integration and security

ANALYSIS RULES:
1. Track technical requirements:
   - API needs
   - Data flow requirements
   - Security constraints
   - Compliance needs

2. Identify integration points:
   - Meeting platforms (Zoom, Teams, Meet)
   - Collaboration tools (Slack, Notion)
   - CRM systems (Salesforce, HubSpot)
   - Custom applications

3. Security concerns:
   - Data residency
   - Encryption requirements
   - Access controls
   - Audit logging

4. Implementation timeline:
   - Basic setup: 1 day
   - Full integration: 1 week
   - Custom workflows: 2-3 weeks
   - Enterprise deployment: 4-6 weeks

API rate limits:
- Standard: 1000 requests/hour
- Team: 5000 requests/hour
- Enterprise: Unlimited

When discussing architecture:
- All data encrypted at rest and in transit
- 99.9% uptime SLA
- Real-time webhooks for events
- Comprehensive audit logs',
                'variables' => [
                    'api_rate_limit' => '1000-unlimited/hour',
                    'setup_time' => '1 day - 6 weeks',
                    'security_cert' => 'SOC2, HIPAA',
                ],
                'talking_points' => [
                    'API documentation walkthrough',
                    'Security and compliance overview',
                    'Integration architecture',
                    'Data flow and storage',
                    'Authentication methods',
                    'Sandbox environment access',
                ],
                'icon' => 'Code',
                'category' => 'technical',
                'is_system' => true,
            ],
            [
                'name' => 'Executive Briefing',
                'description' => 'Present strategic value to C-level executives',
                'prompt' => 'You are analyzing an executive briefing for Clueless adoption.

EXECUTIVE CONTEXT:
- Audience: C-level executives
- Focus: Strategic value, ROI, competitive advantage
- Goal: Secure executive buy-in

MEETING CONTEXT:
- Type: Executive Presentation
- Duration: Usually under 30 minutes
- Decision level: Strategic

ANALYSIS RULES:
1. Track strategic priorities:
   - Digital transformation
   - Productivity improvement
   - Competitive advantage
   - Risk mitigation

2. Business impact metrics:
   - 40% meeting efficiency gain
   - 90% action item completion
   - 50% faster decision making
   - 25% reduction in follow-up meetings

3. Competitive advantages:
   - First-mover in AI meeting intelligence
   - Integration with existing tech stack
   - Scalable across organization
   - Measurable ROI

4. Risk mitigation:
   - Compliance documentation
   - Information capture
   - Audit trails
   - Knowledge retention

Investment levels:
- Department (50 users): $24,000/year
- Division (200 users): $72,000/year  
- Enterprise (1000+ users): Custom pricing

Strategic benefits:
- Transform meeting culture
- Accelerate decision velocity
- Improve execution accuracy
- Enable remote collaboration',
                'variables' => [
                    'roi_timeline' => '3 months',
                    'productivity_gain' => '40%',
                    'strategic_value' => 'Digital transformation enabler',
                ],
                'talking_points' => [
                    'Strategic vision alignment',
                    'Competitive advantage through AI',
                    'Organization-wide impact',
                    'Success metrics and KPIs',
                    'Implementation roadmap',
                    'Expected ROI and timeline',
                ],
                'icon' => 'Briefcase',
                'category' => 'executive',
                'is_system' => true,
            ],
            [
                'name' => 'Support Troubleshooting',
                'description' => 'Resolve technical issues and ensure customer satisfaction',
                'prompt' => 'You are analyzing a support troubleshooting call for Clueless.

SUPPORT CONTEXT:
- Product: Clueless meeting assistant
- Common issues: Audio quality, integration sync, transcription accuracy
- SLA: 24-hour response, 4-hour critical

MEETING CONTEXT:
- Type: Technical Support
- Goal: Resolve issues quickly
- Outcome: Customer satisfaction

ANALYSIS RULES:
1. Categorize the issue:
   - Critical: Service down, data loss
   - High: Feature not working, integration broken
   - Medium: Performance issues, minor bugs
   - Low: Feature requests, how-to questions

2. Track troubleshooting steps:
   - Issue reproduction
   - Diagnostic information
   - Solution attempts
   - Resolution status

3. Customer sentiment:
   - Frustration level
   - Urgency expressed
   - Satisfaction with resolution
   - Likelihood to escalate

4. Common solutions:
   - Audio issues: Check microphone permissions
   - Sync delays: Verify API credentials
   - Accuracy problems: Language settings
   - Integration failures: Re-authenticate

Escalation path:
- L1: Basic troubleshooting (immediate)
- L2: Technical support (4 hours)
- L3: Engineering team (24 hours)
- Executive: Critical business impact

When resolving issues:
- Acknowledge the problem
- Set clear expectations
- Provide workarounds
- Follow up proactively',
                'variables' => [
                    'response_sla' => '24 hours',
                    'critical_sla' => '4 hours',
                    'satisfaction_target' => '95%',
                ],
                'talking_points' => [
                    'Issue validation and reproduction',
                    'Quick workarounds available',
                    'Root cause analysis',
                    'Prevention measures',
                    'Follow-up timeline',
                    'Escalation if needed',
                ],
                'icon' => 'Wrench',
                'category' => 'support',
                'is_system' => true,
            ],
            [
                'name' => 'Partnership Discussion',
                'description' => 'Explore strategic partnerships and integration opportunities',
                'prompt' => 'You are analyzing a partnership discussion for Clueless.

PARTNERSHIP CONTEXT:
- Product: Clueless meeting intelligence platform
- Partnership types: Technology, Channel, Strategic
- Goal: Mutual value creation

MEETING CONTEXT:
- Type: Partnership Exploration
- Participants: Business development teams
- Focus: Synergies and opportunities

ANALYSIS RULES:
1. Partnership type identification:
   - Technology: API integration, joint features
   - Channel: Reseller, referral programs
   - Strategic: Co-marketing, joint GTM

2. Value proposition mapping:
   - What we bring: AI meeting intelligence
   - What they bring: Distribution, technology, customers
   - Joint value: Enhanced offering, market reach

3. Commercial terms discussed:
   - Revenue sharing models
   - Referral commissions (20% year 1)
   - Integration development costs
   - Co-marketing investments

4. Success criteria:
   - Technical feasibility
   - Market alignment
   - Commercial viability
   - Strategic fit

Partnership structures:
- Referral: 20% commission year 1, 10% ongoing
- Reseller: 30% partner discount
- Technology: Revenue share on joint customers
- Strategic: Custom commercial terms

Integration opportunities:
- Embed Clueless in partner platform
- White-label options available
- API access for deep integration
- Co-branded solutions',
                'variables' => [
                    'referral_commission' => '20% year 1',
                    'reseller_discount' => '30%',
                    'integration_timeline' => '60-90 days',
                ],
                'talking_points' => [
                    'Partnership value proposition',
                    'Integration possibilities',
                    'Go-to-market strategy',
                    'Revenue models',
                    'Success metrics',
                    'Next steps and timeline',
                ],
                'icon' => 'Users',
                'category' => 'partnership',
                'is_system' => true,
            ],
            [
                'name' => 'Team Standup Analysis',
                'description' => 'Extract action items and blockers from daily standups',
                'prompt' => 'You are analyzing a team standup meeting using Clueless.

STANDUP CONTEXT:
- Format: Daily standup (15-30 minutes)
- Structure: Yesterday, Today, Blockers
- Goal: Team alignment and blocker removal

MEETING CONTEXT:
- Type: Agile/Scrum Standup
- Participants: Development team
- Frequency: Daily

ANALYSIS RULES:
1. Extract for each participant:
   - Yesterday: What was completed
   - Today: What is planned
   - Blockers: What is preventing progress

2. Identify patterns:
   - Recurring blockers
   - Dependencies between team members
   - Velocity indicators
   - Team health signals

3. Action items:
   - Who needs help
   - What needs escalation
   - Dependencies to resolve
   - Follow-ups required

4. Team metrics:
   - Participation rate
   - Average speaking time
   - Blocker frequency
   - Resolution speed

Output format:
- Participant summaries
- Blocker list with owners
- Dependencies matrix
- Action items with due dates

Red flags to highlight:
- Same blocker multiple days
- Silent participants
- Overcommitment patterns
- Team tension indicators',
                'variables' => [
                    'meeting_duration' => '15-30 minutes',
                    'team_size' => 'Varies',
                    'format' => 'Yesterday/Today/Blockers',
                ],
                'talking_points' => [
                    'What did you complete yesterday?',
                    'What are you working on today?',
                    'Any blockers or impediments?',
                    'Do you need help from anyone?',
                    'Are we on track for sprint goals?',
                ],
                'icon' => 'Users',
                'category' => 'team_meeting',
                'is_system' => true,
            ],
            [
                'name' => 'Quarterly Business Review',
                'description' => 'Analyze QBR meetings for strategic insights and planning',
                'prompt' => 'You are analyzing a Quarterly Business Review using Clueless.

QBR CONTEXT:
- Purpose: Review performance, plan ahead
- Participants: Leadership team, stakeholders
- Frequency: Quarterly

MEETING CONTEXT:
- Type: Strategic Review
- Duration: 60-90 minutes
- Decisions: Budget, priorities, resources

ANALYSIS RULES:
1. Performance metrics:
   - Revenue vs target
   - Key metric achievement
   - Win/loss analysis
   - Customer satisfaction

2. Strategic discussions:
   - Market changes
   - Competitive landscape
   - Product roadmap
   - Investment priorities

3. Planning elements:
   - Next quarter goals
   - Resource allocation
   - Budget adjustments
   - Risk mitigation

4. Action items by category:
   - Strategic initiatives
   - Operational improvements
   - Team changes
   - Process updates

Key sections to track:
- Last quarter review (wins/misses)
- Market analysis
- Financial performance
- Customer insights
- Product updates
- Next quarter plan
- Resource needs
- Risk assessment

Decision tracking:
- Budget approvals
- Headcount changes
- Priority shifts
- Strategic pivots

Output priorities:
- Executive summary
- Key decisions made
- Action items with owners
- Risk register updates
- Success metrics defined',
                'variables' => [
                    'review_period' => 'Quarterly',
                    'planning_horizon' => 'Next 90 days',
                    'key_metrics' => 'Revenue, NPS, Retention',
                ],
                'talking_points' => [
                    'Q3 performance vs targets',
                    'Key wins and achievements',
                    'Missed targets and reasons',
                    'Market changes and impact',
                    'Q4 priorities and goals',
                    'Resource requirements',
                    'Risk mitigation strategies',
                    'Success metrics for Q4',
                ],
                'icon' => 'Calendar',
                'category' => 'strategic',
                'is_system' => true,
            ],
        ];

        foreach ($templates as $template) {
            Template::firstOrCreate(
                [
                    'name' => $template['name'],
                    'is_system' => true,
                ],
                $template
            );
        }
    }
}
