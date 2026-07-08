import axios from 'axios';
import { useMutation } from '@tanstack/react-query';

/**
 * Custom hook to interact with the Gemini AI service.
 * Utilizes React Query's useMutation for handling loading, error, and success states.
 */
export const useAiAssistant = () => {
    return useMutation({
        mutationFn: async (prompt) => {
            // Note: If you have a configured axios instance (e.g., in src/services/api.js),
            // you should import and use that here instead of standard axios.
            const response = await axios.post(
                'http://127.0.0.1:8000/api/ai/ask',
                { prompt },
                {
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    // Required for Laravel Sanctum to authenticate the request
                    withCredentials: true 
                }
            );
            return response.data;
        }
    });
};
