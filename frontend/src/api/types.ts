export interface User {
  id: number;
  name: string;
  email: string;
}

export interface Campaign {
  id: number;
  title: string;
  setting: string;
  status: 'draft' | 'active' | 'completed' | 'archived';
  ai_provider: string | null;
  characters_count?: number;
  sessions_count?: number;
  characters?: Character[];
  active_session?: GameSession | null;
  created_at: string;
}

export interface Character {
  id: number;
  name: string;
  race: string;
  character_class: string;
  level: number;
  stats: Stats;
  hp: number;
  max_hp: number;
  inventory: string[] | null;
  backstory: string | null;
}

export interface Stats {
  str: number;
  dex: number;
  con: number;
  int: number;
  wis: number;
  cha: number;
}

export interface GameSession {
  id: number;
  campaign_id: number;
  session_number: number;
  status: 'active' | 'ended';
  summary: string | null;
  messages?: Message[];
  created_at: string;
}

export interface Message {
  id: number;
  role: 'user' | 'assistant' | 'system';
  type: string | null;
  content: string;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface AuthResponse {
  user: User;
  token: string;
}
