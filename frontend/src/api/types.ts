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
  temp_hp: number;
  armor_class: number;
  speed: number;
  xp: number;
  proficiency_bonus: number;
  gold: number;
  hit_dice_total: number;
  hit_dice_remaining: number;
  death_save_successes: number;
  death_save_failures: number;
  spell_slots: Record<string, number> | null;
  spell_slots_max: Record<string, number> | null;
  proficiencies: Proficiencies | null;
  conditions: Condition[] | null;
  equipped: Record<string, string | boolean> | null;
  class_features: string[] | null;
  known_spells: string[] | null;
  prepared_spells: string[] | null;
  cantrips: string[] | null;
  inventory: string[] | null;
  backstory: string | null;
  template_id: number | null;
  // Derived fields from getFullSheet
  ability_modifiers?: Record<string, number>;
  spell_save_dc?: number | null;
  spell_attack_mod?: number | null;
  spellcasting_ability?: string | null;
  xp_to_next_level?: number;
  xp_next_level_total?: number | null;
  can_level_up?: boolean;
  hit_die?: number;
  equipped_details?: Record<string, Equipment>;
}

export interface Stats {
  str: number;
  dex: number;
  con: number;
  int: number;
  wis: number;
  cha: number;
}

export interface Proficiencies {
  saving_throws: string[];
  armor: string[];
  weapons: string[];
  tools: string[];
  skills: string[];
}

export interface Condition {
  name: string;
  persistent?: boolean;
  [key: string]: unknown;
}

export interface Equipment {
  name: string;
  type: 'weapon' | 'armor' | 'shield';
  damage?: string;
  damage_type?: string;
  weapon_type?: string;
  properties?: string[];
  ac_base?: number;
  max_dex_bonus?: number | null;
  ac_bonus?: number;
  armor_category?: string;
}

export interface Spell {
  name: string;
  level: number;
  school: string;
  casting_time: string;
  range: string;
  components: string[];
  duration: string;
  description: string;
  classes: string[];
  damage?: string;
  damage_type?: string;
  healing?: string;
}

export interface CampaignMap {
  id: number;
  campaign_id: number;
  map_type: 'world' | 'region' | 'location' | 'battle';
  category: string | null;
  location_name: string | null;
  image_source: 'bundled' | 'uploaded' | 'generated';
  image_path: string;
  metadata: Record<string, unknown> | null;
}

export interface MapMetadata {
  id: number;
  image_path: string;
  location_name: string | null;
  category: string | null;
  map_type: string;
}

export interface CombatEncounter {
  id: number;
  game_session_id: number;
  status: 'active' | 'ended';
  round: number;
  current_turn: number;
  initiative_order: Combatant[];
  combat_log: CombatLogEntry[];
}

export interface Combatant {
  name: string;
  hp: number;
  max_hp: number;
  ac: number;
  initiative: number;
  is_player?: boolean;
  character_id?: number;
  attack_modifier?: number;
}

export interface CombatLogEntry {
  attacker: string;
  target: string;
  roll: number;
  target_ac: number;
  hit: boolean;
  damage: number;
  damage_type: string;
  round: number;
  timestamp: string;
}

export interface CharacterTemplate {
  id: number;
  name: string;
  race: string;
  character_class: string;
  base_stats: Stats;
  backstory: string | null;
  characters?: { id: number; campaign_id: number; level: number }[];
}

export interface LevelUpResult {
  old_level: number;
  new_level: number;
  hp_gained: number;
  new_max_hp: number;
  proficiency_bonus: number;
  new_features: string[];
  has_asi: boolean;
  spell_slots_max: Record<string, number> | null;
  error?: string;
}

export interface SpellCastResult {
  success?: boolean;
  spell?: Spell;
  slot_used?: number | null;
  slots_remaining?: Record<string, number>;
  error?: string;
}

export interface RestResult {
  type: 'short' | 'long';
  hp_healed?: number;
  hp_restored?: number;
  hp: number;
  max_hp: number;
  hit_dice_spent?: number;
  hit_dice_regained?: number;
  hit_dice_remaining: number;
  spell_slots_restored?: boolean;
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
