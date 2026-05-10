import pandas as pd
import joblib
from sklearn.preprocessing import StandardScaler, OneHotEncoder
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import GradientBoostingRegressor
from sklearn.pipeline import Pipeline

# 1. Load your dataset
df = pd.read_csv(r"c:\xampp\htdocs\SWENG\ML model\cognitive_dataset.csv")

# 2. Define features based on your notebook structure
numeric_features = ['Age', 'Sleep_Duration', 'Stress_Level', 'Daily_Screen_Time', 'Caffeine_Intake', 'Reaction_Time', 'Memory_Test_Score']
categorical_features = ['Gender', 'Diet_Type', 'Exercise_Frequency']
target = 'Cognitive_Score'

X = df[numeric_features + categorical_features]
y = df[target]

# 3. Create a Preprocessing Pipeline
# This ensures data from your website is treated exactly like your training data
preprocessor = ColumnTransformer(
    transformers=[
        ('num', StandardScaler(), numeric_features),
        ('cat', OneHotEncoder(handle_unknown='ignore'), categorical_features)
    ])

# 4. Use your best model (GradientBoostingRegressor from your summary)
model_pipeline = Pipeline(steps=[
    ('preprocessor', preprocessor),
    ('regressor', GradientBoostingRegressor())
])

# 5. Train the final model
model_pipeline.fit(X, y)

# 6. Export for your website
out = r"c:\xampp\htdocs\SWENG\ML model\cognitive_model_pipeline.pkl"
joblib.dump(model_pipeline, out)
print(f"Model exported to {out}")