import numpy as np
import pandas as pd

np.random.seed(42)
N = 5000

age              = np.random.randint(18, 80, N)
gender           = np.random.choice(['Male', 'Female'], N)
sleep_duration   = np.round(np.random.uniform(3.0, 10.0, N), 1)
stress_level     = np.random.randint(1, 11, N)
diet_type        = np.random.choice(['Balanced', 'Vegetarian', 'Vegan', 'Keto', 'Mediterranean'], N)
daily_screen     = np.round(np.random.uniform(1.0, 14.0, N), 1)
exercise_freq    = np.random.choice(['Rarely', 'Occasionally', 'Frequently', 'Daily'], N)
caffeine_cups    = np.random.randint(0, 6, N).astype(float)   # 0–5 cups/day
reaction_time    = np.round(np.random.uniform(150, 700, N), 1)  # ms
memory_score     = np.round(np.random.uniform(0, 100, N), 1)   # 0–100

# Build a plausible Cognitive_Score (0–100)
score = np.zeros(N)

# Sleep: 7–9h is optimal
sleep_benefit = np.where(sleep_duration < 6, (sleep_duration - 3) * 4,
                np.where(sleep_duration <= 9, 28 + (sleep_duration - 6) * 4,
                28 - (sleep_duration - 9) * 2))

# Reaction time: lower is better (150ms = full points, 700ms = 0 points)
rt_benefit = (700 - reaction_time) / 550 * 30

# Memory score: direct contribution
mem_benefit = memory_score * 0.20

# Stress: lower is better
stress_penalty = stress_level * 1.5

# Caffeine: moderate (1-2 cups) is beneficial, high is negative
caff_benefit = np.where(caffeine_cups == 0, 0,
               np.where(caffeine_cups <= 2, caffeine_cups * 2,
               (caffeine_cups - 2) * -1.5))

# Exercise
exercise_map = {'Daily': 8, 'Frequently': 6, 'Occasionally': 3, 'Rarely': 1}
exercise_benefit = np.array([exercise_map[e] for e in exercise_freq], dtype=float)

# Diet
diet_map = {'Mediterranean': 6, 'Balanced': 5, 'Vegetarian': 4, 'Vegan': 3, 'Keto': 2}
diet_benefit = np.array([diet_map[d] for d in diet_type], dtype=float)

# Screen time: less is better
screen_penalty = np.clip(daily_screen - 4, 0, None) * 0.5

score = (sleep_benefit + rt_benefit + mem_benefit + exercise_benefit + diet_benefit
         + caff_benefit - stress_penalty - screen_penalty)

# Normalize to 0–100
score = score - score.min()
score = score / score.max() * 100

# Add small noise
score += np.random.normal(0, 2, N)
score = np.clip(score, 0, 100).round(2)

df = pd.DataFrame({
    'Age':               age,
    'Gender':            gender,
    'Sleep_Duration':    sleep_duration,
    'Stress_Level':      stress_level,
    'Diet_Type':         diet_type,
    'Daily_Screen_Time': daily_screen,
    'Exercise_Frequency': exercise_freq,
    'Caffeine_Intake':   caffeine_cups,
    'Reaction_Time':     reaction_time,
    'Memory_Test_Score': memory_score,
    'Cognitive_Score':   score,
})

out_path = r"c:\xampp\htdocs\SWENG\ML model\cognitive_dataset.csv"
df.to_csv(out_path, index=False)
print(f"Dataset saved: {out_path}  ({N} rows)")
print(df.describe())
