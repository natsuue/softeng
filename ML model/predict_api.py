from flask import Flask, request, jsonify
import joblib
import pandas as pd

app = Flask(__name__)

# Load the saved pipeline (includes model + scaler + encoder)
model = joblib.load(r"c:\xampp\htdocs\SWENG\ML model\cognitive_model_pipeline.pkl")

@app.route('/predict', methods=['POST'])
def predict():
    try:
        # Receive JSON data from PHP
        data = request.get_json()
        
        # Convert to DataFrame
        input_df = pd.DataFrame([data])
        
        # Make prediction
        prediction = model.predict(input_df)
        
        return jsonify({
            'success': True,
            'cognitive_score': round(float(prediction[0]), 2)
        })
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})

if __name__ == '__main__':
    # Runs on http://localhost:5000
    app.run(port=5000, debug=True)